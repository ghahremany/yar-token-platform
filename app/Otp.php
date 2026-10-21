<?php
declare(strict_types=1);

namespace App;

use App\Crypto\Totp;
use App\Social\MessengerClient;

/**
 * Manageable Multi-Channel One-Time Password (OTP) Engine:
 * - Supports RFC 6238 TOTP Authenticator (Wallet Yar Android App / Google Authenticator QR)
 * - Supports Instant Bale Messenger OTP & Telegram Messenger OTP (dual or preferred delivery!)
 * - Supports Local Protected File OTP fallback (storage/otp/latest.log, mode 0600) for offline/test setups
 * - Supports 8 Argon2id single-use emergency Recovery Codes for Super-Admins
 */
final class Otp
{
    public static function maskId(string $id): string
    {
        $id = trim($id);
        if ($id === '') return ltr('—');
        if (strlen($id) <= 4) return ltr($id);
        return ltr(substr($id, 0, 2) . '••••' . substr($id, -2));
    }

    /**
     * Issues a new one-time login challenge for an Admin or User.
     *
     * @return array{selector:string,channels_sent:array<int,string>,totp_available:bool,expires_in:int}
     */
    public static function issueAdminChallenge(array $admin): array
    {
        $digits = max(4, min(8, (int) Settings::get('otp_digits', '6')));
        $ttl = max(60, min(900, (int) Settings::get('otp_ttl_seconds', '180')));
        $maxAttempts = max(3, min(10, (int) Settings::get('otp_max_attempts', '5')));

        $min = 10 ** ($digits - 1);
        $max = (10 ** $digits) - 1;
        $code = (string) random_int($min, $max);
        $selector = bin2hex(random_bytes(16));
        $hash = password_hash($code, PASSWORD_ARGON2ID);

        $sent = [];
        $pref = Settings::get('otp_delivery_default', 'totp_or_messenger');
        $appTitle = Settings::get('token_name', 'یارکوین');
        $msg = "🔐 <b>کد تایید ورود به پنل مدیریت {$appTitle}</b>\n\n"
             . "کد یکبار مصرف شما: <code>{$code}</code>\n"
             . "⏱ اعتبار: " . fa_digits($ttl) . " ثانیه\n"
             . "🌐 آی‌پی درخواست: <code>" . e(IpGuard::clientIp()) . "</code>\n\n"
             . "⚠️ این کد را در اختیار هیچ فردی قرار ندهید.";

        if (!empty($admin['bale_chat_id']) && in_array($pref, ['totp_or_messenger', 'bale', 'all'], true)) {
            $res = MessengerClient::sendMessage('bale', (string) $admin['bale_chat_id'], $msg);
            if (!empty($res['ok'])) {
                $sent[] = 'بله (' . self::maskId((string) $admin['bale_chat_id']) . ')';
            }
        }
        if (!empty($admin['telegram_chat_id']) && in_array($pref, ['totp_or_messenger', 'telegram', 'all'], true)) {
            $res = MessengerClient::sendMessage('telegram', (string) $admin['telegram_chat_id'], $msg);
            if (!empty($res['ok'])) {
                $sent[] = 'تلگرام (' . self::maskId((string) $admin['telegram_chat_id']) . ')';
            }
        }

        $totpAvailable = !empty($admin['totp_enabled']) && !empty($admin['totp_secret_enc']);
        if ($totpAvailable) {
            $sent[] = 'اپلیکیشن احرازکننده (Wallet Yar / Authenticator)';
        }

        // Fallback file delivery if explicitly enabled OR no external channel succeeded yet
        if ($pref === 'file' || empty($sent)) {
            self::writeProtectedOtpLog('admin:' . $admin['username'], $code);
            $sent[] = 'فایل محلی سرور (storage/otp/latest.log)';
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO otp_challenges (scope, target_id, token_selector, code_hash, delivery_channel, max_attempts, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([
            'admin_login',
            (int) $admin['id'],
            $selector,
            $hash,
            implode(', ', $sent),
            $maxAttempts,
            IpGuard::clientIp(),
            $ttl,
        ]);

        return [
            'selector'       => $selector,
            'channels_sent'  => $sent,
            'totp_available' => $totpAvailable,
            'expires_in'     => $ttl,
        ];
    }

    /**
     * Issues a login / confirmation OTP challenge for a Wallet / Agent user.
     *
     * @return array{selector:string,channels_sent:array<int,string>,expires_in:int}
     */
    public static function issueUserChallenge(array $user, string $scope = 'user_login'): array
    {
        $digits = max(4, min(8, (int) Settings::get('otp_digits', '6')));
        $ttl = max(60, min(900, (int) Settings::get('otp_ttl_seconds', '180')));
        $code = (string) random_int(10 ** ($digits - 1), (10 ** $digits) - 1);
        $selector = bin2hex(random_bytes(16));
        $hash = password_hash($code, PASSWORD_ARGON2ID);

        $sent = [];
        $appTitle = Settings::get('token_name', 'یارکوین');
        $msg = "🔐 <b>کد یکبار مصرف کیف پول {$appTitle}</b>\n\n"
             . "کد تایید شما: <code>{$code}</code>\n"
             . "⏱ اعتبار: " . fa_digits($ttl) . " ثانیه";

        if (!empty($user['bale_user_id'])) {
            $res = MessengerClient::sendMessage('bale', (string) $user['bale_user_id'], $msg);
            if (!empty($res['ok'])) $sent[] = 'بله';
        }
        if (!empty($user['telegram_user_id'])) {
            $res = MessengerClient::sendMessage('telegram', (string) $user['telegram_user_id'], $msg);
            if (!empty($res['ok'])) $sent[] = 'تلگرام';
        }
        if (!empty($user['totp_enabled']) && !empty($user['totp_secret_enc'])) {
            $sent[] = 'اپلیکیشن احرازکننده (TOTP)';
        }
        if (Settings::get('otp_delivery_default', '') === 'file' || empty($sent)) {
            self::writeProtectedOtpLog('user:' . ($user['username'] ?: $user['wallet_code']), $code);
            $sent[] = 'فایل امن سرور';
        }

        $stmt = Db::pdo()->prepare(
            'INSERT INTO otp_challenges (scope, target_id, token_selector, code_hash, delivery_channel, max_attempts, ip_address, expires_at)
             VALUES (?, ?, ?, ?, ?, 5, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$scope, (int) $user['id'], $selector, $hash, implode(', ', $sent), IpGuard::clientIp(), $ttl]);

        return [
            'selector'      => $selector,
            'channels_sent' => $sent,
            'expires_in'    => $ttl,
        ];
    }

    /**
     * Verifies an Admin OTP input against:
     * 1) Active one-time code sent via Bale / Telegram / File, OR
     * 2) Admin's bound RFC 6238 TOTP secret (Wallet Yar / Authenticator), OR
     * 3) Admin's single-use Argon2id Recovery Codes!
     */
    public static function verifyAdminChallenge(string $selector, string $rawInput): ?array
    {
        $input = trim(en_digits($rawInput));
        if ($selector === '' || $input === '') {
            return null;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM otp_challenges
             WHERE scope = "admin_login" AND token_selector = ? AND consumed_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$selector]);
        $ch = $stmt->fetch();
        if (!$ch) {
            return null;
        }
        if ((int) $ch['attempts'] >= (int) $ch['max_attempts']) {
            return null;
        }

        $pdo->prepare('UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $ch['id']]);

        $adminStmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $adminStmt->execute([(int) $ch['target_id']]);
        $admin = $adminStmt->fetch();
        if (!$admin) {
            return null;
        }

        $matched = false;
        $digitsOnly = preg_replace('/\D+/', '', $input) ?? '';

        // 1. Check dynamic one-time code
        if ($digitsOnly !== '' && password_verify($digitsOnly, (string) $ch['code_hash'])) {
            $matched = true;
        }

        // 2. Check TOTP (Wallet Yar / Google Authenticator) with RFC 6238 §5.2 anti-replay slice guard
        if (!$matched && !empty($admin['totp_enabled']) && !empty($admin['totp_secret_enc'])) {
            $secret = decrypt_secret((string) $admin['totp_secret_enc']);
            $slice = 0;
            if ($secret !== '' && Totp::verify($secret, $digitsOnly, 1, null, $slice)) {
                if ($slice > (int) ($admin['totp_last_slice'] ?? 0)) {
                    $matched = true;
                    $pdo->prepare('UPDATE admins SET totp_last_slice = ? WHERE id = ?')
                        ->execute([$slice, (int) $admin['id']]);
                }
            }
        }

        // 3. Check Argon2id Recovery Codes (e.g. XXXX-XXXX)
        if (!$matched && !empty($admin['recovery_hashes_json'])) {
            $hashes = json_decode((string) $admin['recovery_hashes_json'], true) ?: [];
            $normRec = strtoupper(str_replace([' ', '-'], '', $input));
            foreach ($hashes as $idx => $h) {
                if (is_string($h) && password_verify($normRec, $h)) {
                    $matched = true;
                    unset($hashes[$idx]);
                    $pdo->prepare('UPDATE admins SET recovery_hashes_json = ? WHERE id = ?')
                        ->execute([json_encode(array_values($hashes)), (int) $admin['id']]);
                    Audit::record('admin', (int) $admin['id'], 'admin.recovery_code_used', 'ورود اضطراری با کد بازیابی انجام شد');
                    break;
                }
            }
        }

        if (!$matched) {
            return null;
        }

        $pdo->prepare('UPDATE otp_challenges SET consumed_at = NOW() WHERE id = ?')->execute([(int) $ch['id']]);
        return $admin;
    }

    public static function verifyUserChallenge(string $selector, string $rawInput): ?array
    {
        $input = preg_replace('/\D+/', '', en_digits($rawInput)) ?? '';
        if ($selector === '' || $input === '') return null;

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM otp_challenges
             WHERE scope = "user_login" AND token_selector = ? AND consumed_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$selector]);
        $ch = $stmt->fetch();
        if (!$ch || (int) $ch['attempts'] >= (int) $ch['max_attempts']) {
            return null;
        }
        $pdo->prepare('UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $ch['id']]);

        $user = Users::findById((int) $ch['target_id']);
        if (!$user || (int) $user['is_frozen'] === 1) {
            return null;
        }

        $matched = password_verify($input, (string) $ch['code_hash']);
        if (!$matched && !empty($user['totp_enabled']) && !empty($user['totp_secret_enc'])) {
            $sec = decrypt_secret((string) $user['totp_secret_enc']);
            $slice = 0;
            if ($sec !== '' && Totp::verify($sec, $input, 1, null, $slice)) {
                if ($slice > (int) ($user['totp_last_slice'] ?? 0)) {
                    $matched = true;
                    $pdo->prepare('UPDATE users SET totp_last_slice = ? WHERE id = ?')
                        ->execute([$slice, (int) $user['id']]);
                }
            }
        }
        if (!$matched) {
            return null;
        }

        $pdo->prepare('UPDATE otp_challenges SET consumed_at = NOW() WHERE id = ?')->execute([(int) $ch['id']]);
        return $user;
    }

    /**
     * Generates 8 fresh single-use emergency recovery codes (returned ONCE to display/download, stored Argon2id-hashed).
     *
     * @return array<int,string>
     */
    public static function regenerateRecoveryCodes(int $adminId): array
    {
        $plain = [];
        $hashes = [];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($i = 0; $i < 8; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $formatted = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $plain[] = $formatted;
            $hashes[] = password_hash($raw, PASSWORD_ARGON2ID);
        }
        Db::pdo()->prepare('UPDATE admins SET recovery_hashes_json = ? WHERE id = ?')
            ->execute([json_encode($hashes), $adminId]);
        return $plain;
    }

    private static function writeProtectedOtpLog(string $subject, string $code): void
    {
        $dir = root_path('storage/otp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir . '/latest.log';
        $content = sprintf("[%s] subject=%s code=%s ip=%s\n", date('Y-m-d H:i:s'), $subject, $code, IpGuard::clientIp());
        file_put_contents($file, $content, LOCK_EX);
        @chmod($file, 0600);
    }
}
