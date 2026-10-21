<?php
declare(strict_types=1);

namespace App;

use App\Crypto\Totp;
use App\Social\MessengerClient;
use PDO;

/**
 * Automated Web & CLI System Installer:
 * - Connects to MySQL (creates database if permitted)
 * - Imports database/schema.sql idempotently
 * - Seeds default token settings, creates Super-Admin with Argon2id + TOTP + Recovery Codes
 * - Mints optional Genesis Token Supply into Treasury
 * - Writes gitignored .env with chmod 0600 (never touches tracked config.php!)
 */
final class Installer
{
    /** @return array<string,bool> */
    public static function requirements(): array
    {
        return [
            'PHP >= 8.1'          => version_compare(PHP_VERSION, '8.1.0', '>='),
            'PDO MySQL'           => extension_loaded('pdo_mysql'),
            'BCMath (دقت اعشاری)' => extension_loaded('bcmath'),
            'GMP (منحنی امضا)'    => extension_loaded('gmp'),
            'OpenSSL (AES-GCM)'   => extension_loaded('openssl'),
            'cURL (پیام‌رسان‌ها)' => extension_loaded('curl'),
            'MBString (فارسی)'    => extension_loaded('mbstring'),
            'Storage قابل نوشتن'  => is_writable(root_path('storage')),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function install(array $input): array
    {
        foreach (self::requirements() as $label => $ok) {
            if (!$ok) {
                throw new \RuntimeException("پیش‌نیاز سیستم برآورده نشده است: {$label}");
            }
        }

        $dbHost = trim((string) ($input['db_host'] ?? '127.0.0.1'));
        $dbPort = max(1, min(65535, (int) ($input['db_port'] ?? 3306)));
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($input['db_name'] ?? 'yartoken_db')) ?: 'yartoken_db';
        $dbUser = trim((string) ($input['db_user'] ?? 'root'));
        $dbPass = (string) ($input['db_pass'] ?? '');

        $appUrl = rtrim(trim((string) ($input['app_url'] ?? '')), '/');
        $tokenName = trim((string) ($input['token_name'] ?? 'یارکوین')) ?: 'یارکوین';
        $tokenNameEn = trim((string) ($input['token_name_en'] ?? 'YarCoin')) ?: 'YarCoin';
        $tokenSymbol = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($input['token_symbol'] ?? 'YAR')) ?: 'YAR');
        $genesisSupply = dec_norm($input['genesis_supply'] ?? '1000000');
        $feeBps = max(0, min(10000, (int) ($input['fee_bps'] ?? 100)));
        $feeFixed = dec_norm($input['fee_fixed'] ?? '0.25');
        $priceIrt = dec_norm($input['token_price_irt'] ?? '8500');

        $adminUser = strtolower(preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($input['admin_username'] ?? 'admin')) ?: 'admin');
        $adminPass = (string) ($input['admin_password'] ?? '');
        if (strlen($adminPass) < 8) {
            throw new \InvalidArgumentException('رمز عبور مدیر کل باید حداقل ۸ کاراکتر باشد.');
        }

        $baleToken = trim((string) ($input['bale_bot_token'] ?? ''));
        $tgToken = trim((string) ($input['telegram_bot_token'] ?? ''));
        $tgApiBase = trim((string) ($input['telegram_api_base'] ?? ''));
        $adminBaleId = trim((string) ($input['admin_bale_id'] ?? ''));
        $adminTgId = trim((string) ($input['admin_telegram_id'] ?? ''));

        // 1. Connect to MySQL & create DB if possible
        $serverDsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
        $rawPdo = new PDO($serverDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rawPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        unset($rawPdo);

        // 2. Write .env (chmod 0600) cleanly without touching tracked config.php
        $appSecret = bin2hex(random_bytes(32));
        $webhookSecret = bin2hex(random_bytes(24));
        $bridgeSecret = bin2hex(random_bytes(24));
        $cronKey = bin2hex(random_bytes(20));

        $envLines = [
            'APP_NAME=' . self::envQuote($tokenName . ' | سامانه توکن و کیف پول هوشمند'),
            'APP_URL=' . self::envQuote($appUrl),
            'APP_ENV="production"',
            'APP_SECRET="' . $appSecret . '"',
            'TRUST_PROXY="' . (!empty($input['trust_proxy']) ? 'true' : 'false') . '"',
            '',
            'DB_HOST=' . self::envQuote($dbHost),
            'DB_PORT="' . $dbPort . '"',
            'DB_NAME=' . self::envQuote($dbName),
            'DB_USER=' . self::envQuote($dbUser),
            'DB_PASS=' . self::envQuote($dbPass),
            '',
            'WEBHOOK_SECRET="' . $webhookSecret . '"',
            'BRIDGE_HMAC_SECRET="' . $bridgeSecret . '"',
            'CRON_KEY="' . $cronKey . '"',
        ];
        file_put_contents(root_path('.env'), implode("\n", $envLines) . "\n");
        @chmod(root_path('.env'), 0600);

        Config::reload();
        Db::reset();
        Settings::flush();

        // 3. Import schema.sql cleanly statement by statement
        $schemaSql = file_get_contents(root_path('database/schema.sql')) ?: '';
        $cleanSql = preg_replace('/^\s*--.*$/m', '', $schemaSql) ?? $schemaSql;
        foreach (explode(';', $cleanSql) as $stmtSql) {
            $stmtSql = trim($stmtSql);
            if ($stmtSql !== '') {
                Db::pdo()->exec($stmtSql);
            }
        }

        // 4. Populate defaults + custom token parameters + encrypted messenger tokens
        $defaults = Settings::defaults();
        $defaults['token_name'] = $tokenName;
        $defaults['token_name_en'] = $tokenNameEn;
        $defaults['token_symbol'] = $tokenSymbol;
        $defaults['fee_bps'] = (string) $feeBps;
        $defaults['fee_fixed'] = $feeFixed;
        $defaults['token_price_irt'] = $priceIrt;
        if ($baleToken !== '') {
            $defaults['bale_bot_token_enc'] = encrypt_secret($baleToken);
        }
        if ($tgToken !== '') {
            $defaults['telegram_bot_token_enc'] = encrypt_secret($tgToken);
        }
        if ($tgApiBase !== '') {
            $defaults['telegram_api_base_override'] = $tgApiBase;
        }
        $defaults['bridge_hmac_secret_enc'] = encrypt_secret($bridgeSecret);
        Settings::setMany($defaults);

        // 5. Create Super-Admin with built-in RFC 6238 TOTP Secret (ready to scan in Wallet Yar Android App!) + 8 Recovery Codes
        $totpSecret = Totp::generateSecret();
        $stmt = Db::pdo()->prepare(
            'INSERT INTO admins (username, display_name, password_hash, role, bale_chat_id, telegram_chat_id, totp_secret_enc, totp_enabled)
             VALUES (?, ?, ?, "superadmin", ?, ?, ?, 1)'
        );
        $stmt->execute([
            $adminUser,
            'مدیر کل شبکه',
            password_hash($adminPass, PASSWORD_ARGON2ID),
            $adminBaleId,
            $adminTgId,
            encrypt_secret($totpSecret),
        ]);
        $adminId = (int) Db::pdo()->lastInsertId();
        $recoveryCodes = Otp::regenerateRecoveryCodes($adminId);

        // 6. Initialize System Accounts & Mint Genesis Token Supply into Treasury!
        $sys = Users::ensureSystemAccounts();
        $genesisTx = null;
        if (bccomp($genesisSupply, '0', 8) > 0) {
            $genesisTx = TokenEngine::mint(
                (int) $sys['treasury']['id'],
                $genesisSupply,
                $adminId,
                "ضرب اولیه پیدایش شبکه (Genesis Mint — {$tokenSymbol})",
                'genesis-mint-1'
            );
        }

        // 7. Auto-register Bale & Telegram webhooks if URL is public HTTPS
        if ($appUrl !== '' && str_starts_with($appUrl, 'https://')) {
            if ($baleToken !== '') {
                MessengerClient::call('bale', 'setWebhook', [
                    'url'          => $appUrl . '/webhooks/bale.php',
                    'secret_token' => $webhookSecret,
                ]);
            }
            if ($tgToken !== '') {
                MessengerClient::call('telegram', 'setWebhook', [
                    'url'          => $appUrl . '/webhooks/telegram.php',
                    'secret_token' => $webhookSecret,
                ]);
            }
        }

        file_put_contents(root_path('storage/installed.lock'), date('c') . "\n");
        @chmod(root_path('storage/installed.lock'), 0600);

        return [
            'admin_id'       => $adminId,
            'admin_username' => $adminUser,
            'totp_secret'    => $totpSecret,
            'totp_uri'       => Totp::uri($totpSecret, $adminUser, $tokenNameEn),
            'recovery_codes' => $recoveryCodes,
            'genesis_tx'     => $genesisTx,
            'cron_key'       => $cronKey,
            'webhook_secret' => $webhookSecret,
        ];
    }

    private static function envQuote(string $v): string
    {
        return '"' . addcslashes($v, '"\\') . '"';
    }
}
