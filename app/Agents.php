<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Representatives / Lenders (نمایندگان وام‌دهنده و مدیران زیرمجموعه) Engine.
 */
final class Agents
{
    public static function getProfile(int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT a.*, u.wallet_code, u.evm_address, u.username, u.full_name, u.balance, u.phone, u.is_frozen
             FROM agents a
             JOIN users u ON u.id = a.user_id
             WHERE a.user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    public static function findByCode(string $code): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT a.*, u.wallet_code, u.evm_address, u.username, u.full_name, u.balance
             FROM agents a
             JOIN users u ON u.id = a.user_id
             WHERE UPPER(a.agent_code) = UPPER(?) AND a.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([trim($code)]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function listAll(): array
    {
        return Db::pdo()->query(
            'SELECT a.*, u.wallet_code, u.evm_address, u.username, u.full_name, u.balance,
                    (SELECT COUNT(*) FROM users su WHERE su.parent_agent_id = a.user_id) AS subusers_count,
                    (SELECT COUNT(*) FROM loans ln WHERE ln.agent_user_id = a.user_id AND ln.status IN ("active","overdue")) AS active_loans_count
             FROM agents a
             JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC'
        )->fetchAll();
    }

    /**
     * Promotes a user to Representative / Lender or updates their lending parameters.
     *
     * @param array<string,mixed> $params
     */
    public static function upsertAgent(int $userId, array $params, ?int $adminId = null): array
    {
        $pdo = Db::pdo();
        $user = Users::findById($userId);
        if (!$user) {
            throw new \RuntimeException('کاربر موردنظر یافت نشد.');
        }

        $code = strtoupper(trim((string) ($params['agent_code'] ?? '')));
        if ($code === '') {
            $code = 'AG-' . strtoupper(bin2hex(random_bytes(3)));
        }

        $title = mb_substr(trim((string) ($params['title'] ?? ('نمایندگی ' . $user['full_name']))), 0, 140);
        $poolLimit = dec_norm($params['credit_pool_limit'] ?? '0');
        $maxInterest = min(10000, max(0, (int) ($params['max_interest_bps'] ?? 1200)));
        $maxLoanUser = dec_norm($params['max_loan_per_user'] ?? '10000');
        $maxTerm = min(1095, max(1, (int) ($params['max_term_days'] ?? 180)));
        $commBps = min(9000, max(0, (int) ($params['commission_bps'] ?? 2000)));
        $discBps = min(10000, max(0, (int) ($params['transfer_fee_discount_bps'] ?? 5000)));
        $canWallet = !empty($params['can_lend_from_wallet']) ? 1 : 0;
        $canPool = !empty($params['can_lend_from_pool']) ? 1 : 0;
        $isActive = isset($params['is_active']) ? ((int) $params['is_active'] ? 1 : 0) : 1;
        $notes = trim((string) ($params['notes'] ?? ''));

        Db::tx(function (PDO $pdo) use (
            $userId, $code, $title, $poolLimit, $maxInterest, $maxLoanUser,
            $maxTerm, $commBps, $discBps, $canWallet, $canPool, $isActive, $notes
        ): void {
            $pdo->prepare('UPDATE users SET account_type = "agent" WHERE id = ? AND account_type = "user"')
                ->execute([$userId]);

            $stmt = $pdo->prepare(
                'INSERT INTO agents (
                    user_id, agent_code, title, credit_pool_limit, max_interest_bps, max_loan_per_user,
                    max_term_days, commission_bps, transfer_fee_discount_bps, can_lend_from_wallet, can_lend_from_pool, is_active, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    agent_code = VALUES(agent_code),
                    title = VALUES(title),
                    credit_pool_limit = VALUES(credit_pool_limit),
                    max_interest_bps = VALUES(max_interest_bps),
                    max_loan_per_user = VALUES(max_loan_per_user),
                    max_term_days = VALUES(max_term_days),
                    commission_bps = VALUES(commission_bps),
                    transfer_fee_discount_bps = VALUES(transfer_fee_discount_bps),
                    can_lend_from_wallet = VALUES(can_lend_from_wallet),
                    can_lend_from_pool = VALUES(can_lend_from_pool),
                    is_active = VALUES(is_active),
                    notes = VALUES(notes)'
            );
            $stmt->execute([
                $userId, $code, $title, $poolLimit, $maxInterest, $maxLoanUser,
                $maxTerm, $commBps, $discBps, $canWallet, $canPool, $isActive, $notes,
            ]);
        });

        Audit::record($adminId ? 'admin' : 'system', $adminId, 'agent.upsert', "تنظیم/ارتقای نمایندگی «{$title}» با سقف اعتبار " . fmt_token($poolLimit));
        return self::getProfile($userId) ?? [];
    }

    /**
     * Attaches an existing unassigned user (or creates a brand-new sub-user wallet) under this Agent.
     */
    public static function attachSubUser(int $agentUserId, string $identifier): array
    {
        $agent = self::getProfile($agentUserId);
        if (!$agent || (int) $agent['is_active'] !== 1) {
            throw new \RuntimeException('نمایندگی فعال نیست.');
        }

        $target = Users::resolveRecipient($identifier);
        if (!$target) {
            throw new \RuntimeException('کاربری با این مشخصات یافت نشد.');
        }
        if ((int) $target['id'] === $agentUserId) {
            throw new \InvalidArgumentException('امکان افزودن خود نماینده به عنوان زیرمجموعه وجود ندارد.');
        }
        if (!in_array($target['account_type'], ['user', 'merchant'], true)) {
            throw new \InvalidArgumentException('این حساب قابل ثبت به عنوان زیرمجموعه نیست.');
        }
        if (!empty($target['parent_agent_id']) && (int) $target['parent_agent_id'] !== $agentUserId) {
            throw new \RuntimeException('این کاربر قبلاً زیرمجموعه نماینده دیگری ثبت شده است.');
        }

        Db::pdo()->prepare('UPDATE users SET parent_agent_id = ? WHERE id = ?')
            ->execute([$agentUserId, (int) $target['id']]);

        return Users::findById((int) $target['id']) ?? [];
    }

    /**
     * Creates a brand-new sub-user account directly under this Agent.
     *
     * @param array<string,mixed> $data
     */
    public static function registerSubUser(int $agentUserId, array $data): array
    {
        $agent = self::getProfile($agentUserId);
        if (!$agent || (int) $agent['is_active'] !== 1) {
            throw new \RuntimeException('نمایندگی فعال نیست.');
        }
        $data['parent_agent_id'] = $agentUserId;
        return Users::create($data);
    }

    /** @return array<int,array<string,mixed>> */
    public static function listSubUsers(int $agentUserId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT u.*,
                    (SELECT COALESCE(SUM(remaining_amount), 0) FROM loans l WHERE l.borrower_user_id = u.id AND l.status IN ("active","overdue")) AS active_debt,
                    (SELECT COUNT(*) FROM loans l WHERE l.borrower_user_id = u.id AND l.status = "repaid") AS repaid_loans
             FROM users u
             WHERE u.parent_agent_id = ?
             ORDER BY u.id DESC'
        );
        $stmt->execute([$agentUserId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    public static function dashboardMetrics(int $agentUserId): array
    {
        $agent = self::getProfile($agentUserId);
        if (!$agent) {
            throw new \RuntimeException('نماینده یافت نشد.');
        }
        $pdo = Db::pdo();

        $subCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE parent_agent_id = " . (int) $agentUserId)->fetchColumn();
        $commEarned = (string) ($pdo->query("SELECT COALESCE(SUM(agent_commission_amount), 0) FROM ledger_entries WHERE meta_json LIKE '%\"agent_comm_to\":{$agentUserId}%'")->fetchColumn() ?: '0');
        $activeLoansAmt = (string) ($pdo->query("SELECT COALESCE(SUM(remaining_amount), 0) FROM loans WHERE agent_user_id = {$agentUserId} AND status IN ('active','overdue')")->fetchColumn() ?: '0');
        $pendingReq = (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE agent_user_id = {$agentUserId} AND status = 'requested'")->fetchColumn();
        $overdueCount = (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE agent_user_id = {$agentUserId} AND status = 'overdue'")->fetchColumn();

        $availablePool = bcsub(dec_norm((string) $agent['credit_pool_limit']), dec_norm((string) $agent['credit_pool_used']), 8);
        if (bccomp($availablePool, '0', 8) < 0) $availablePool = '0.00000000';

        return [
            'agent'               => $agent,
            'subusers_count'      => $subCount,
            'available_pool'      => $availablePool,
            'commissions_earned'  => dec_norm($commEarned),
            'active_receivables'  => dec_norm($activeLoansAmt),
            'pending_requests'    => $pendingReq,
            'overdue_loans_count' => $overdueCount,
        ];
    }
}
