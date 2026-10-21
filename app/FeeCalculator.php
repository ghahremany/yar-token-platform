<?php
declare(strict_types=1);

namespace App;

/**
 * Super-Admin Dynamic Fee Calculation & Split Engine.
 * Supports:
 * - Zero fee, Fixed fee, Percentage (BPS), or Hybrid (Fixed + BPS%)
 * - Minimum floor (fee_min) & Hard ceiling (fee_max)
 * - Sender-pays-extra vs Deduct-from-amount (CommunityToken.sol compatible!)
 * - Automatic exemptions for system accounts (Treasury / Fee Vault / Escrow)
 * - VIP user discount & Agent outgoing transfer discount
 * - Three-way fee distribution:
 *     1) Deflationary Auto-Burn -> Burn Sink (0x...dEaD)
 *     2) Representative Commission -> Sender's / Receiver's Agent Wallet
 *     3) Protocol Revenue -> Super-Admin Fee Collector Vault
 */
final class FeeCalculator
{
    private const BPS_DENOM = '10000';

    /**
     * Quotes exact 8-decimal amounts for a proposed transfer.
     *
     * @return array{
     *   gross_amount:string,
     *   fee_amount:string,
     *   sender_total_debit:string,
     *   receiver_net_credit:string,
     *   burn_share:string,
     *   agent_commission:string,
     *   agent_beneficiary_id:?int,
     *   vault_share:string,
     *   fee_payer_mode:string,
     *   applied_rule:string
     * }
     */
    public static function quote(
        array $sender,
        array $receiver,
        string $rawAmount,
        string $channel = 'web',
        bool $forceZeroFee = false
    ): array {
        $amount = dec_norm($rawAmount);
        if (bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ انتقال باید بزرگ‌تر از صفر باشد.');
        }

        $payerMode = Settings::get('fee_payer_mode', 'sender_extra');
        $exemptTypes = ['treasury', 'fee_vault', 'burn_sink', 'escrow'];

        // System accounts or explicit zero-fee operations pay 0 fee
        if (
            $forceZeroFee ||
            in_array($sender['account_type'] ?? '', $exemptTypes, true) ||
            in_array($receiver['account_type'] ?? '', $exemptTypes, true) ||
            ($sender['fee_tier'] ?? '') === 'exempt'
        ) {
            return self::buildResult($amount, '0.00000000', $payerMode, 'exempt', $sender, $receiver);
        }

        // Special group tipping fee rule if configured
        if ($channel === 'group') {
            $groupBps = dec_norm(Settings::get('group_tip_fee_bps', '0'));
            $fee = bcdiv(bcmul($amount, $groupBps, 12), self::BPS_DENOM, 8);
            return self::buildResult($amount, $fee, $payerMode, 'group_tip', $sender, $receiver);
        }

        $mode = Settings::get('fee_mode', 'hybrid');
        $bps = dec_norm(Settings::get('fee_bps', '100'));
        $fixed = dec_norm(Settings::get('fee_fixed', '0.25000000'));
        $minFee = dec_norm(Settings::get('fee_min', '0'));
        $maxFee = dec_norm(Settings::get('fee_max', '0'));

        $pctPart = bcdiv(bcmul($amount, $bps, 12), self::BPS_DENOM, 8);
        $fee = match ($mode) {
            'zero'       => '0.00000000',
            'fixed'      => $fixed,
            'percentage' => $pctPart,
            default      => bcadd($fixed, $pctPart, 8), // hybrid
        };

        if ($mode !== 'zero') {
            if (bccomp($minFee, '0', 8) > 0 && bccomp($fee, $minFee, 8) < 0) {
                $fee = $minFee;
            }
            if (bccomp($maxFee, '0', 8) > 0 && bccomp($fee, $maxFee, 8) > 0) {
                $fee = $maxFee;
            }
        }

        $rule = $mode;

        // Apply Agent discount if sender is an active Agent
        if (($sender['account_type'] ?? '') === 'agent') {
            $ag = Agents::getProfile((int) $sender['id']);
            if ($ag && (int) $ag['transfer_fee_discount_bps'] > 0) {
                $discBps = (string) min(10000, (int) $ag['transfer_fee_discount_bps']);
                $keepBps = bcsub(self::BPS_DENOM, $discBps, 0);
                $fee = bcdiv(bcmul($fee, $keepBps, 12), self::BPS_DENOM, 8);
                $rule .= '+agent_discount';
            }
        } elseif (($sender['fee_tier'] ?? '') === 'vip') {
            $discBps = (string) min(10000, (int) Settings::get('vip_discount_bps', '5000'));
            $keepBps = bcsub(self::BPS_DENOM, $discBps, 0);
            $fee = bcdiv(bcmul($fee, $keepBps, 12), self::BPS_DENOM, 8);
            $rule .= '+vip_discount';
        }

        // If deduct_from_amount, fee can never equal or exceed gross amount
        if ($payerMode === 'deduct_from_amount' && bccomp($fee, $amount, 8) >= 0) {
            throw new \InvalidArgumentException('مبلغ انتقالی کمتر یا مساوی کارمزد شبکه است.');
        }

        return self::buildResult($amount, $fee, $payerMode, $rule, $sender, $receiver);
    }

    /** @return array<string,mixed> */
    private static function buildResult(
        string $amount,
        string $fee,
        string $payerMode,
        string $rule,
        array $sender,
        array $receiver
    ): array {
        if ($payerMode === 'deduct_from_amount') {
            $senderDebit = $amount;
            $receiverCredit = bcsub($amount, $fee, 8);
        } else {
            $senderDebit = bcadd($amount, $fee, 8);
            $receiverCredit = $amount;
        }

        $burnShare = '0.00000000';
        $agentComm = '0.00000000';
        $agentBeneficiaryId = null;
        $vaultShare = '0.00000000';

        if (bccomp($fee, '0', 8) > 0) {
            // 1. Deflationary auto-burn share
            $burnBps = (string) min(10000, max(0, (int) Settings::get('fee_burn_share_bps', '0')));
            if (bccomp($burnBps, '0', 0) > 0) {
                $burnShare = bcdiv(bcmul($fee, $burnBps, 12), self::BPS_DENOM, 8);
            }

            // 2. Representative / Agent commission share if sender (or receiver) belongs to an active Agent
            $parentAgentId = !empty($sender['parent_agent_id'])
                ? (int) $sender['parent_agent_id']
                : (!empty($receiver['parent_agent_id']) ? (int) $receiver['parent_agent_id'] : 0);

            if ($parentAgentId > 0) {
                $ag = Agents::getProfile($parentAgentId);
                if ($ag && (int) $ag['is_active'] === 1 && (int) $ag['commission_bps'] > 0) {
                    $remAfterBurn = bcsub($fee, $burnShare, 8);
                    $commBps = (string) min(9000, (int) $ag['commission_bps']);
                    $agentComm = bcdiv(bcmul($remAfterBurn, $commBps, 12), self::BPS_DENOM, 8);
                    $agentBeneficiaryId = $parentAgentId;
                }
            }

            // 3. Remaining fee goes to Super-Admin Fee Collector Vault
            $vaultShare = bcsub(bcsub($fee, $burnShare, 8), $agentComm, 8);
        }

        return [
            'gross_amount'         => $amount,
            'fee_amount'           => $fee,
            'sender_total_debit'   => $senderDebit,
            'receiver_net_credit'  => $receiverCredit,
            'burn_share'           => $burnShare,
            'agent_commission'     => $agentComm,
            'agent_beneficiary_id' => $agentBeneficiaryId,
            'vault_share'          => $vaultShare,
            'fee_payer_mode'       => $payerMode,
            'applied_rule'         => $rule,
        ];
    }
}
