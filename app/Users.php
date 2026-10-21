<?php
declare(strict_types=1);

namespace App;

use App\Crypto\Keccak256;
use PDO;

final class Users
{
    public const TREASURY_ADDRESS  = '0x0000000000000000000000000000000000000001';
    public const FEE_VAULT_ADDRESS = '0x00000000000000000000000000000000000000FE';
    public const BURN_ADDRESS      = '0x000000000000000000000000000000000000dEaD';
    public const ESCROW_ADDRESS    = '0x000000000000000000000000000000000000E5C0';

    /**
     * Ensures the 4 core protocol accounts exist idempotently (Treasury, Fee Vault, Burn Sink, Group Escrow).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function ensureSystemAccounts(): array
    {
        $defs = [
            'treasury'  => [self::TREASURY_ADDRESS,  'YAR-TREASURY', 'treasury',  'خزانه مرکزی توکن (Treasury)'],
            'fee_vault' => [self::FEE_VAULT_ADDRESS, 'YAR-FEES',     'fee_vault', 'صندوق کارمزد شبکه (Fee Collector)'],
            'burn_sink' => [self::BURN_ADDRESS,      'YAR-BURN',     'burn_sink', 'آدرس توکن‌سوزی دائمی (0x...dEaD)'],
            'escrow'    => [self::ESCROW_ADDRESS,    'YAR-ESCROW',   'escrow',    'صندوق امانی بسته‌های هدیه (Escrow)'],
        ];
        $pdo = Db::pdo();
        $out = [];
        foreach ($defs as $key => [$rawAddr, $wcode, $type, $name]) {
            $addr = Keccak256::checksumAddress($rawAddr);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE account_type = ? LIMIT 1');
            $stmt->execute([$type]);
            $row = $stmt->fetch();
            if (!$row) {
                $ins = $pdo->prepare(
                    'INSERT INTO users (account_type, wallet_code, evm_address, username, full_name, fee_tier)
                     VALUES (?, ?, ?, ?, ?, "exempt")'
                );
                $ins->execute([$type, $wcode, $addr, $type, $name]);
                $stmt->execute([$type]);
                $row = $stmt->fetch();
            }
            $out[$key] = $row;
        }
        return $out;
    }

    public static function systemAccount(string $type): array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE account_type = ? LIMIT 1');
        $stmt->execute([$type]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $all = self::ensureSystemAccounts();
        return $all[$type];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    /**
     * Universal Wallet Resolver: resolves a wallet by:
     * - 0x... EVM address (case-insensitive)
     * - YAR-XXXXXX wallet code
     * - @username or username
     * - phone number
     * - bale:12345 / tg:12345
     */
    public static function resolveRecipient(string $identifier): ?array
    {
        $q = trim($identifier);
        if ($q === '') return null;

        // Strip URI scheme if user pasted QR payload e.g. ethereum:0x...?value=... or yartoken:0x...
        if (preg_match('/^(?:ethereum|yartoken):([0-9a-zA-Z\-xX]+)/i', $q, $m)) {
            $q = $m[1];
        }

        $pdo = Db::pdo();
        if (Keccak256::isValidAddress($q)) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(evm_address) = LOWER(?) LIMIT 1');
            $stmt->execute([$q]);
            return $stmt->fetch() ?: null;
        }

        if (str_starts_with(strtoupper($q), 'YAR-')) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE wallet_code = ? LIMIT 1');
            $stmt->execute([strtoupper($q)]);
            return $stmt->fetch() ?: null;
        }

        $cleanUser = ltrim($q, '@');
        $stmt = $pdo->prepare(
            'SELECT * FROM users
             WHERE username = ? OR phone = ? OR bale_user_id = ? OR telegram_user_id = ?
             LIMIT 1'
        );
        $stmt->execute([$cleanUser, $cleanUser, $cleanUser, $cleanUser]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Creates a new Wallet User (from Web, Android ECDSA Wallet, Social Bot, or Agent onboarding).
     *
     * @param array<string,mixed> $data
     */
    public static function create(array $data): array
    {
        $pdo = Db::pdo();

        $evmAddress = !empty($data['evm_address'])
            ? Keccak256::checksumAddress((string) $data['evm_address'])
            : self::generateUniqueAddress($pdo);

        $walletCode = self::generateUniqueWalletCode($pdo);
        $username = !empty($data['username']) ? strtolower(trim((string) $data['username'])) : null;
        $phone = !empty($data['phone']) ? trim(en_digits((string) $data['phone'])) : null;
        $passHash = !empty($data['password']) ? password_hash((string) $data['password'], PASSWORD_ARGON2ID) : null;

        $stmt = $pdo->prepare(
            'INSERT INTO users (
                account_type, wallet_code, evm_address, public_key_hex, username, phone,
                full_name, password_hash, parent_agent_id, bale_user_id, telegram_user_id, external_social_id, last_active_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $data['account_type'] ?? 'user',
            $walletCode,
            $evmAddress,
            (string) ($data['public_key_hex'] ?? ''),
            $username,
            $phone,
            mb_substr(trim((string) ($data['full_name'] ?? 'کاربر کیف پول')), 0, 140),
            $passHash,
            !empty($data['parent_agent_id']) ? (int) $data['parent_agent_id'] : null,
            !empty($data['bale_user_id']) ? (string) $data['bale_user_id'] : null,
            !empty($data['telegram_user_id']) ? (string) $data['telegram_user_id'] : null,
            !empty($data['external_social_id']) ? (string) $data['external_social_id'] : null,
        ]);

        $newId = (int) $pdo->lastInsertId();

        // Optional automatic welcome faucet from Treasury if Treasury has balance & setting > 0
        $welcome = Settings::get('signup_welcome_tokens', '0');
        if (bccomp($welcome, '0', 8) > 0 && empty($data['skip_welcome'])) {
            try {
                $treasury = self::systemAccount('treasury');
                if (bccomp((string) $treasury['balance'], $welcome, 8) >= 0) {
                    TokenEngine::transfer(
                        (int) $treasury['id'],
                        $newId,
                        $welcome,
                        'system',
                        'هدیه خوش‌آمدگویی ساخت کیف پول',
                        'welcome-' . $newId
                    );
                }
            } catch (\Throwable) {
                // Non-fatal if treasury is empty
            }
        }

        return self::findById($newId) ?? [];
    }

    /**
     * Finds or auto-creates a wallet for a Bale / Telegram / External Social user (supports seamless Group Tipping!).
     */
    public static function findOrCreateBySocial(
        string $platform,
        string $socialId,
        string $displayName = 'کاربر اجتماعی',
        ?string $agentCode = null
    ): array {
        $col = match ($platform) {
            'bale'     => 'bale_user_id',
            'telegram' => 'telegram_user_id',
            default    => 'external_social_id',
        };
        $storedId = $platform === 'bridge' ? $socialId : $socialId;

        $stmt = Db::pdo()->prepare("SELECT * FROM users WHERE {$col} = ? LIMIT 1");
        $stmt->execute([$storedId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return $existing;
        }

        $parentAgentId = null;
        if ($agentCode !== null && $agentCode !== '') {
            $ag = Agents::findByCode($agentCode);
            if ($ag) {
                $parentAgentId = (int) $ag['user_id'];
            }
        }

        return self::create([
            'full_name'       => $displayName !== '' ? $displayName : 'کاربر پیام‌رسان',
            $col              => $storedId,
            'parent_agent_id' => $parentAgentId,
        ]);
    }

    /**
     * Generates a 6-character pairing code allowing a user to merge/link their Android Non-Custodial Wallet
     * with their Bale/Telegram/Web account!
     */
    public static function issueSocialLinkCode(int $userId): string
    {
        $code = strtoupper(bin2hex(random_bytes(3)));
        $stmt = Db::pdo()->prepare(
            'INSERT INTO otp_challenges (scope, target_id, token_selector, code_hash, delivery_channel, max_attempts, ip_address, expires_at)
             VALUES ("social_link", ?, ?, ?, "pair", 3, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
        );
        $stmt->execute([$userId, 'LNK-' . $code, password_hash($code, PASSWORD_DEFAULT), IpGuard::clientIp()]);
        return $code;
    }

    /**
     * Links a social messenger identity (Bale/Telegram) into an existing Wallet account (e.g., Android Wallet Yar user).
     * Automatically transfers any balance held in a temporary social account over to the primary wallet!
     */
    public static function consumeSocialLinkCode(string $code, string $platform, string $socialUserId): array
    {
        $clean = strtoupper(trim($code));
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM otp_challenges
             WHERE scope = "social_link" AND token_selector = ? AND consumed_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['LNK-' . $clean]);
        $ch = $stmt->fetch();
        if (!$ch || !password_verify($clean, (string) $ch['code_hash'])) {
            throw new \RuntimeException('کد اتصال نامعتبر یا منقضی شده است.');
        }

        $col = $platform === 'telegram' ? 'telegram_user_id' : 'bale_user_id';
        $targetUserId = (int) $ch['target_id'];

        $pdo->prepare('UPDATE otp_challenges SET consumed_at = NOW() WHERE id = ?')->execute([(int) $ch['id']]);

        // Check if another temporary account currently owns this social ID
        $prevStmt = $pdo->prepare("SELECT * FROM users WHERE {$col} = ? AND id <> ? LIMIT 1");
        $prevStmt->execute([$socialUserId, $targetUserId]);
        $prev = $prevStmt->fetch();

        if ($prev) {
            // Detach social ID from temporary account and move remaining funds cleanly with zero fee!
            $pdo->prepare("UPDATE users SET {$col} = NULL WHERE id = ?")->execute([(int) $prev['id']]);
            if (bccomp((string) $prev['balance'], '0', 8) > 0) {
                TokenEngine::transfer(
                    (int) $prev['id'],
                    $targetUserId,
                    (string) $prev['balance'],
                    'system_merge',
                    'ادغام موجودی حساب پیام‌رسان با کیف پول اصلی',
                    'merge-' . $prev['id'] . '-' . $targetUserId,
                    true
                );
            }
        }

        $pdo->prepare("UPDATE users SET {$col} = ? WHERE id = ?")->execute([$socialUserId, $targetUserId]);
        return self::findById($targetUserId) ?? [];
    }

    private static function generateUniqueAddress(PDO $pdo): string
    {
        while (true) {
            $addr = Keccak256::checksumAddress('0x' . bin2hex(random_bytes(20)));
            $st = $pdo->prepare('SELECT id FROM users WHERE evm_address = ? LIMIT 1');
            $st->execute([$addr]);
            if (!$st->fetch()) return $addr;
        }
    }

    private static function generateUniqueWalletCode(PDO $pdo): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        while (true) {
            $s = 'YAR-';
            for ($i = 0; $i < 6; $i++) {
                $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $st = $pdo->prepare('SELECT id FROM users WHERE wallet_code = ? LIMIT 1');
            $st->execute([$s]);
            if (!$st->fetch()) return $s;
        }
    }
}
