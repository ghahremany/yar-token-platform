<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Append-Only Cryptographically Hash-Chained Ledger Verifier & Recorder.
 * Every ledger row hashes:
 *   SHA256(prev_hash | tx_type | from_user_id | to_user_id | gross | net | fee | burned | agent_comm | channel | created_at)
 * giving full tamper-evident blockchain-grade auditability on standard MySQL!
 */
final class Ledger
{
    public const GENESIS_HASH = '0x0000000000000000000000000000000000000000000000000000000000000000';

    public static function computeHash(
        string $prevHash,
        string $txType,
        ?int $fromId,
        ?int $toId,
        string $gross,
        string $net,
        string $fee,
        string $burned,
        string $agentComm,
        string $channel,
        string $timestamp
    ): string {
        $canonical = implode('|', [
            strtolower($prevHash),
            $txType,
            $fromId ?? 0,
            $toId ?? 0,
            dec_norm($gross),
            dec_norm($net),
            dec_norm($fee),
            dec_norm($burned),
            dec_norm($agentComm),
            $channel,
            $timestamp,
        ]);
        return '0x' . hash('sha256', $canonical);
    }

    /**
     * Appends a new verified hash-chained row inside an active DB transaction (locks tail row FOR UPDATE).
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function appendLocked(PDO $pdo, array $entry): array
    {
        $tailStmt = $pdo->query('SELECT tx_hash FROM ledger_entries ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $tail = $tailStmt->fetch();
        $prevHash = $tail ? (string) $tail['tx_hash'] : self::GENESIS_HASH;
        $now = date('Y-m-d H:i:s');

        $gross = dec_norm($entry['gross_amount'] ?? '0');
        $net = dec_norm($entry['net_amount'] ?? '0');
        $fee = dec_norm($entry['fee_amount'] ?? '0');
        $burned = dec_norm($entry['burned_amount'] ?? '0');
        $agentComm = dec_norm($entry['agent_commission_amount'] ?? '0');
        $channel = substr((string) ($entry['channel'] ?? 'web'), 0, 32);
        $txType = (string) $entry['tx_type'];
        $fromId = isset($entry['from_user_id']) ? (int) $entry['from_user_id'] : null;
        $toId = isset($entry['to_user_id']) ? (int) $entry['to_user_id'] : null;

        $txHash = self::computeHash(
            $prevHash,
            $txType,
            $fromId,
            $toId,
            $gross,
            $net,
            $fee,
            $burned,
            $agentComm,
            $channel,
            $now
        );

        $stmt = $pdo->prepare(
            'INSERT INTO ledger_entries (
                tx_hash, prev_hash, tx_type, from_user_id, to_user_id,
                gross_amount, net_amount, fee_amount, burned_amount, agent_commission_amount,
                sender_balance_after, receiver_balance_after, channel, reference_id,
                idempotency_key, signature_hex, memo, meta_json, created_by_admin_id, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $txHash,
            $prevHash,
            $txType,
            $fromId,
            $toId,
            $gross,
            $net,
            $fee,
            $burned,
            $agentComm,
            isset($entry['sender_balance_after']) ? dec_norm($entry['sender_balance_after']) : null,
            isset($entry['receiver_balance_after']) ? dec_norm($entry['receiver_balance_after']) : null,
            $channel,
            $entry['reference_id'] ?? null,
            $entry['idempotency_key'] ?? null,
            $entry['signature_hex'] ?? null,
            mb_substr((string) ($entry['memo'] ?? ''), 0, 255),
            isset($entry['meta']) ? json_encode($entry['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $entry['created_by_admin_id'] ?? null,
            $now,
        ]);

        $id = (int) $pdo->lastInsertId();
        return [
            'id'               => $id,
            'tx_hash'          => $txHash,
            'prev_hash'        => $prevHash,
            'tx_type'          => $txType,
            'gross'            => $gross,
            'net'              => $net,
            'fee'              => $fee,
            'burned'           => $burned,
            'agent_comm'       => $agentComm,
            'gross_amount'     => $gross,
            'net_amount'       => $net,
            'fee_amount'       => $fee,
            'burned_amount'    => $burned,
            'agent_commission' => $agentComm,
            'created_at'       => $now,
        ];
    }

    /**
     * Full cryptographic chain audit + conservation of supply check:
     * Verifies EVERY block link from Genesis AND verifies that:
     *   SUM(all user & vault balances, excluding burn_sink) + burn_sink.balance === total_minted!
     *
     * @return array<string,mixed>
     */
    public static function verifyChainIntegrity(): array
    {
        $pdo = Db::pdo();
        $rows = $pdo->query('SELECT * FROM ledger_entries ORDER BY id ASC')->fetchAll();
        $expectedPrev = self::GENESIS_HASH;
        $brokenAt = null;

        foreach ($rows as $r) {
            if ((string) $r['prev_hash'] !== $expectedPrev) {
                $brokenAt = ['id' => (int) $r['id'], 'reason' => 'عدم تطابق prev_hash'];
                break;
            }
            $recomputed = self::computeHash(
                (string) $r['prev_hash'],
                (string) $r['tx_type'],
                $r['from_user_id'] !== null ? (int) $r['from_user_id'] : null,
                $r['to_user_id'] !== null ? (int) $r['to_user_id'] : null,
                (string) $r['gross_amount'],
                (string) $r['net_amount'],
                (string) $r['fee_amount'],
                (string) $r['burned_amount'],
                (string) $r['agent_commission_amount'],
                (string) $r['channel'],
                (string) $r['created_at']
            );
            if ($recomputed !== (string) $r['tx_hash']) {
                $brokenAt = ['id' => (int) $r['id'], 'reason' => 'دست‌کاری محتوای رکورد دفتر کل تشخیص داده شد'];
                break;
            }
            $expectedPrev = (string) $r['tx_hash'];
        }

        $minted = (string) ($pdo->query("SELECT COALESCE(SUM(net_amount), 0) FROM ledger_entries WHERE tx_type = 'mint'")->fetchColumn() ?: '0');
        $totalHeld = (string) ($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users")->fetchColumn() ?: '0');
        $burnSinkBal = (string) ($pdo->query("SELECT COALESCE(SUM(balance), 0) FROM users WHERE account_type = 'burn_sink'")->fetchColumn() ?: '0');
        $circulating = bcsub(dec_norm($totalHeld), dec_norm($burnSinkBal), 8);
        $discrepancy = bcsub(dec_norm($minted), dec_norm($totalHeld), 8);

        return [
            'ok'                 => $brokenAt === null && bccomp($discrepancy, '0', 8) === 0,
            'blocks_checked'     => count($rows),
            'head_hash'          => $expectedPrev,
            'broken_at'          => $brokenAt,
            'total_minted'       => dec_norm($minted),
            'total_burned'       => dec_norm($burnSinkBal),
            'circulating_supply' => $circulating,
            'supply_discrepancy' => $discrepancy,
        ];
    }
}
