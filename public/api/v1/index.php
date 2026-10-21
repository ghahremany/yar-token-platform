<?php
declare(strict_types=1);

define('YAR_STATELESS', true);
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Agents;
use App\ApiAuth;
use App\Crypto\Secp256k1;
use App\Db;
use App\FeeCalculator;
use App\Invoices;
use App\IpGuard;
use App\Ledger;
use App\Loans;
use App\Otp;
use App\RateLimiter;
use App\Settings;
use App\TokenEngine;
use App\Users;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Idempotency-Key');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!is_installed()) {
    json_response(['ok' => false, 'error' => 'سامانه هنوز نصب نشده است.'], 503);
}

$ip = IpGuard::clientIp();
if (!RateLimiter::allow('api:' . $ip, 180, 60)) {
    json_response(['ok' => false, 'error' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً کمی صبر کنید.'], 429);
}

$rawBody = file_get_contents('php://input') ?: '';
$jsonIn = $rawBody !== '' ? (json_decode($rawBody, true) ?: []) : [];
$in = array_merge($_GET, $_POST, is_array($jsonIn) ? $jsonIn : []);
$action = trim((string) ($in['action'] ?? 'token.info'));

try {
    switch ($action) {
        case 'health':
            $integrity = Ledger::verifyChainIntegrity();
            json_response([
                'ok'           => true,
                'status'       => 'healthy',
                'ledger_valid' => $integrity['ok'],
                'blocks'       => $integrity['blocks_checked'],
                'head_hash'    => $integrity['head_hash'],
                'server_time'  => date('c'),
            ]);

        case 'token.info':
            $sup = TokenEngine::supplyOverview();
            json_response([
                'ok'       => true,
                'token'    => $sup,
                'fees'     => [
                    'mode'             => Settings::get('fee_mode', 'hybrid'),
                    'bps'              => (int) Settings::get('fee_bps', '100'),
                    'fixed'            => Settings::get('fee_fixed', '0.25000000'),
                    'min'              => Settings::get('fee_min', '0.10000000'),
                    'max'              => Settings::get('fee_max', '250.00000000'),
                    'payer_mode'       => Settings::get('fee_payer_mode', 'sender_extra'),
                    'burn_share_bps'   => (int) Settings::get('fee_burn_share_bps', '2000'),
                ],
                'endpoints' => [
                    'rest_api' => base_url('api/v1/'),
                    'evm_rpc'  => base_url('rpc.php'),
                ],
            ]);

        case 'auth.challenge':
            if (!RateLimiter::allow('auth_ch:' . $ip, 25, 60)) {
                json_response(['ok' => false, 'error' => 'درخواست بیش از حد مجاز.'], 429);
            }
            $ch = ApiAuth::issueWalletChallenge((string) ($in['address'] ?? ''));
            json_response(['ok' => true] + $ch);

        case 'auth.verify_signature':
            $res = ApiAuth::verifyWalletSignature(
                (string) ($in['address'] ?? ''),
                (string) ($in['nonce'] ?? ''),
                (string) ($in['public_key'] ?? ''),
                (string) ($in['signature'] ?? ''),
                (string) ($in['device_name'] ?? 'Wallet Yar Android'),
                isset($in['agent_code']) ? (string) $in['agent_code'] : null
            );
            unset($res['user']['password_hash'], $res['user']['totp_secret_enc']);
            json_response(['ok' => true] + $res);

        case 'auth.login':
            if (!RateLimiter::allow('api_login:' . $ip, 12, 300)) {
                json_response(['ok' => false, 'error' => 'تعداد تلاش‌های ورود بیش از حد مجاز است.'], 429);
            }
            $u = Users::resolveRecipient((string) ($in['identifier'] ?? $in['username'] ?? ''));
            if (!$u || empty($u['password_hash']) || !password_verify((string) ($in['password'] ?? ''), (string) $u['password_hash'])) {
                json_response(['ok' => false, 'error' => 'نام کاربری یا رمز عبور نادرست است.'], 401);
            }
            if ((int) $u['is_frozen'] === 1) {
                json_response(['ok' => false, 'error' => 'این حساب مسدود شده است.'], 403);
            }
            if (Settings::bool('user_web_otp_required', false) || !empty($u['totp_enabled'])) {
                $ch = Otp::issueUserChallenge($u, 'user_login');
                json_response(['ok' => true, 'otp_required' => true] + $ch);
            }
            $tok = ApiAuth::issueBearerToken((int) $u['id'], (string) ($in['device_name'] ?? 'Mobile Password Auth'));
            unset($tok['user']['password_hash'], $tok['user']['totp_secret_enc']);
            json_response(['ok' => true, 'otp_required' => false] + $tok);

        case 'auth.verify_otp':
            $u = Otp::verifyUserChallenge((string) ($in['selector'] ?? ''), (string) ($in['code'] ?? ''));
            if (!$u) {
                json_response(['ok' => false, 'error' => 'کد یکبار مصرف نامعتبر یا منقضی است.'], 401);
            }
            $tok = ApiAuth::issueBearerToken((int) $u['id'], (string) ($in['device_name'] ?? 'Mobile OTP Auth'));
            unset($tok['user']['password_hash'], $tok['user']['totp_secret_enc']);
            json_response(['ok' => true] + $tok);

        case 'wallet.me':
            $user = ApiAuth::requireUser();
            $uid = (int) $user['id'];
            $txStmt = Db::pdo()->prepare(
                'SELECT id, tx_hash, tx_type, from_user_id, to_user_id, gross_amount, net_amount, fee_amount, channel, memo, created_at
                 FROM ledger_entries WHERE from_user_id = ? OR to_user_id = ? ORDER BY id DESC LIMIT 15'
            );
            $txStmt->execute([$uid, $uid]);

            $lnStmt = Db::pdo()->prepare('SELECT * FROM loans WHERE borrower_user_id = ? AND status IN ("active","overdue","requested") ORDER BY id DESC');
            $lnStmt->execute([$uid]);

            $agentProfile = ($user['account_type'] === 'agent') ? Agents::dashboardMetrics($uid) : null;
            $priceIrt = (float) Settings::get('token_price_irt', '8500');
            $priceUsd = (float) Settings::get('token_price_usd', '0.10');

            unset($user['password_hash'], $user['totp_secret_enc'], $user['bot_state_data']);
            json_response([
                'ok'           => true,
                'wallet'       => $user,
                'valuation'    => [
                    'balance_irt' => (float) bcmul((string) $user['balance'], (string) $priceIrt, 2),
                    'balance_usd' => (float) bcmul((string) $user['balance'], (string) $priceUsd, 4),
                    'rate_irt'    => $priceIrt,
                    'rate_usd'    => $priceUsd,
                ],
                'transactions' => $txStmt->fetchAll(),
                'active_loans' => $lnStmt->fetchAll(),
                'agent'        => $agentProfile,
            ]);

        case 'wallet.quote':
            $user = ApiAuth::requireUser();
            $rec = Users::resolveRecipient((string) ($in['to'] ?? ''));
            if (!$rec) {
                json_response(['ok' => false, 'error' => 'کیف پول گیرنده یافت نشد.'], 404);
            }
            $quote = FeeCalculator::quote($user, $rec, (string) ($in['amount'] ?? '0'), 'android');
            json_response([
                'ok'        => true,
                'recipient' => [
                    'wallet_code' => $rec['wallet_code'],
                    'evm_address' => $rec['evm_address'],
                    'full_name'   => $rec['full_name'],
                ],
                'quote'     => $quote,
            ]);

        case 'wallet.transfer':
            $user = ApiAuth::requireUser();
            $toRaw = trim((string) ($in['to'] ?? ''));
            $rec = Users::resolveRecipient($toRaw);
            if (!$rec) {
                json_response(['ok' => false, 'error' => 'کیف پول مقصد یافت نشد.'], 404);
            }
            $amountNorm = dec_norm($in['amount'] ?? '0');
            $sigHex = !empty($in['signature']) ? trim((string) $in['signature']) : null;

            // If non-custodial wallet attaches an ECDSA signature over the canonical transfer payload, verify it strictly!
            if ($sigHex !== null) {
                $pubKey = !empty($in['public_key']) ? (string) $in['public_key'] : (string) $user['public_key_hex'];
                $txNonce = (string) ($in['tx_nonce'] ?? $user['tx_nonce']);
                $msg = ApiAuth::canonicalTransferMessage((string) $user['evm_address'], $toRaw, $amountNorm, $txNonce);
                $digest = Secp256k1::personalMessageDigest($msg);
                if (!Secp256k1::verifyDigest($digest, $sigHex, $pubKey, (string) $user['evm_address'])) {
                    json_response(['ok' => false, 'error' => 'امضای دیجیتال تراکنش معتبر نیست!'], 403);
                }
            }

            $idem = trim((string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $in['idempotency_key'] ?? ''));
            $txRes = TokenEngine::transfer(
                (int) $user['id'],
                (int) $rec['id'],
                $amountNorm,
                'android',
                (string) ($in['memo'] ?? ''),
                $idem !== '' ? $idem : null,
                false,
                $sigHex
            );

            json_response([
                'ok'        => true,
                'duplicate' => $txRes['duplicate'],
                'tx'        => $txRes['tx'],
                'quote'     => $txRes['quote'] ?? null,
                'new_balance' => Users::findById((int) $user['id'])['balance'] ?? '0',
            ]);

        case 'wallet.pair_code':
            $user = ApiAuth::requireUser();
            $code = Users::issueSocialLinkCode((int) $user['id']);
            json_response(['ok' => true, 'pair_code' => $code, 'expires_in' => 600]);

        case 'wallet.transactions':
            $user = ApiAuth::requireUser();
            $uid = (int) $user['id'];
            $limit = max(1, min(100, (int) ($in['limit'] ?? 30)));
            $stmt = Db::pdo()->prepare(
                "SELECT id, tx_hash, prev_hash, tx_type, from_user_id, to_user_id, gross_amount, net_amount, fee_amount, burned_amount, channel, memo, created_at
                 FROM ledger_entries WHERE from_user_id = ? OR to_user_id = ? ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([$uid, $uid]);
            json_response(['ok' => true, 'items' => $stmt->fetchAll()]);

        case 'loans.my':
            $user = ApiAuth::requireUser();
            $stmt = Db::pdo()->prepare('SELECT * FROM loans WHERE borrower_user_id = ? ORDER BY id DESC LIMIT 30');
            $stmt->execute([(int) $user['id']]);
            $loans = [];
            foreach ($stmt->fetchAll() as $row) {
                $loans[] = Loans::findById((int) $row['id']);
            }
            json_response(['ok' => true, 'loans' => $loans]);

        case 'loans.request':
            $user = ApiAuth::requireUser();
            $loan = Loans::requestLoan(
                (int) $user['id'],
                (string) ($in['amount'] ?? '0'),
                (int) ($in['installments_count'] ?? 3),
                (int) ($in['interval_days'] ?? 30),
                (string) ($in['purpose'] ?? '')
            );
            json_response(['ok' => true, 'loan' => $loan]);

        case 'loans.repay':
            $user = ApiAuth::requireUser();
            $rep = Loans::repayInstallment(
                (int) ($in['loan_id'] ?? 0),
                (int) $user['id'],
                !empty($in['full']),
                'android'
            );
            json_response([
                'ok'          => true,
                'paid_amount' => $rep['paid_amount'],
                'remaining'   => $rep['remaining'],
                'loan_status' => $rep['loan_status'],
                'tx'          => $rep['tx'],
            ]);

        case 'agent.overview':
            $user = ApiAuth::requireUser();
            if ($user['account_type'] !== 'agent') {
                json_response(['ok' => false, 'error' => 'حساب شما دارای مجوز نمایندگی نیست.'], 403);
            }
            $metrics = Agents::dashboardMetrics((int) $user['id']);
            $subs = Agents::listSubUsers((int) $user['id']);
            $lnStmt = Db::pdo()->prepare(
                'SELECT l.*, u.full_name AS borrower_name, u.wallet_code AS borrower_code
                 FROM loans l JOIN users u ON u.id = l.borrower_user_id
                 WHERE l.agent_user_id = ? ORDER BY l.id DESC LIMIT 40'
            );
            $lnStmt->execute([(int) $user['id']]);
            json_response([
                'ok'        => true,
                'metrics'   => $metrics,
                'sub_users' => $subs,
                'loans'     => $lnStmt->fetchAll(),
            ]);

        case 'agent.add_subuser':
            $user = ApiAuth::requireUser();
            $sub = Agents::attachSubUser((int) $user['id'], (string) ($in['identifier'] ?? ''));
            json_response(['ok' => true, 'sub_user' => [
                'id'           => $sub['id'],
                'wallet_code'  => $sub['wallet_code'],
                'evm_address'  => $sub['evm_address'],
                'full_name'    => $sub['full_name'],
                'credit_score' => $sub['credit_score'],
            ]]);

        case 'agent.grant_loan':
            $user = ApiAuth::requireUser();
            $sub = Users::resolveRecipient((string) ($in['borrower'] ?? ''));
            if (!$sub) {
                json_response(['ok' => false, 'error' => 'کاربر زیرمجموعه یافت نشد.'], 404);
            }
            $loan = Loans::grantLoan((int) $user['id'], (int) $sub['id'], (string) ($in['amount'] ?? '0'), [
                'funding_source'     => $in['funding_source'] ?? 'credit_pool',
                'interest_bps'       => (int) ($in['interest_bps'] ?? 500),
                'installments_count' => (int) ($in['installments_count'] ?? 3),
                'interval_days'      => (int) ($in['interval_days'] ?? 30),
                'auto_deduct'        => !isset($in['auto_deduct']) || (bool) $in['auto_deduct'],
                'purpose'            => (string) ($in['purpose'] ?? 'تسهیلات نمایندگی از اپلیکیشن'),
                'request_loan_id'    => $in['request_loan_id'] ?? null,
            ]);
            json_response(['ok' => true, 'loan' => $loan]);

        case 'invoices.create':
            $user = ApiAuth::requireUser();
            $inv = Invoices::create(
                (int) $user['id'],
                (string) ($in['amount'] ?? '0'),
                (string) ($in['title'] ?? 'خرید آنلاین'),
                (string) ($in['callback_url'] ?? ''),
                (string) ($in['external_order_id'] ?? ''),
                (int) ($in['ttl_minutes'] ?? 30),
                (string) ($in['return_url'] ?? '')
            );
            json_response([
                'ok'           => true,
                'invoice'      => $inv,
                'checkout_url' => base_url('pay/?code=' . urlencode((string) $inv['invoice_code'])),
            ]);

        case 'invoices.status':
            $inv = Invoices::findByCode((string) ($in['code'] ?? $_GET['code'] ?? ''));
            if (!$inv) {
                json_response(['ok' => false, 'error' => 'صورت‌حساب یافت نشد.'], 404);
            }
            json_response(['ok' => true, 'invoice' => $inv]);

        case 'invoices.pay':
            $user = ApiAuth::requireUser();
            $txRes = Invoices::pay((string) ($in['code'] ?? ''), (int) $user['id'], 'android');
            json_response(['ok' => true, 'tx' => $txRes['tx']]);

        case 'invoices.refund':
            $user = ApiAuth::requireUser();
            $txRes = Invoices::refund((string) ($in['code'] ?? ''), (int) $user['id'], null);
            json_response(['ok' => true, 'tx' => $txRes['tx']]);

        case 'packets.create':
            $user = ApiAuth::requireUser();
            $pkt = \App\Social\RedPackets::create(
                (int) $user['id'],
                (string) ($in['amount'] ?? '0'),
                (int) ($in['max_claims'] ?? 5),
                'web',
                '',
                (string) ($in['mode'] ?? 'lucky'),
                (string) ($in['title'] ?? 'پاکت هدیه گروهی'),
                (string) ($in['passcode'] ?? ''),
                (int) ($in['ttl_hours'] ?? 24)
            );
            json_response([
                'ok'        => true,
                'packet'    => $pkt,
                'share_url' => base_url('packet/?code=' . urlencode((string) $pkt['packet_code'])),
            ]);

        case 'packets.details':
            $pkt = \App\Social\RedPackets::details((string) ($in['code'] ?? $_GET['code'] ?? ''));
            if (!$pkt) {
                json_response(['ok' => false, 'error' => 'پاکت هدیه یافت نشد.'], 404);
            }
            unset($pkt['passcode_hash']);
            json_response(['ok' => true, 'packet' => $pkt]);

        case 'packets.claim':
            $user = ApiAuth::requireUser();
            $claim = \App\Social\RedPackets::claim((string) ($in['code'] ?? ''), (int) $user['id'], (string) ($in['passcode'] ?? ''));
            json_response(['ok' => true, 'claim' => $claim]);

        case 'audit.run':
            json_response(['ok' => true, 'audit' => \App\Auditor::runFullAudit()]);

        default:
            json_response(['ok' => false, 'error' => "عملیات ناشناخته: {$action}"], 400);
    }
} catch (\Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
