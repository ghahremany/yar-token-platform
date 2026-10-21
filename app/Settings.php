<?php
declare(strict_types=1);

namespace App;

final class Settings
{
    /** @var ?array<string,string> */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $rows = Db::pdo()->query('SELECT skey, sval FROM settings')->fetchAll();
            foreach ($rows as $r) {
                self::$cache[(string) $r['skey']] = (string) $r['sval'];
            }
        } catch (\Throwable) {
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default ? '1' : '0');
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function set(string $key, string|int|float|bool $value): void
    {
        $val = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO settings (skey, sval) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
        );
        $stmt->execute([$key, $val]);
        if (self::$cache !== null) {
            self::$cache[$key] = $val;
        }
    }

    /** @param array<string,string|int|float|bool> $pairs */
    public static function setMany(array $pairs): void
    {
        Db::tx(function () use ($pairs): void {
            foreach ($pairs as $k => $v) {
                self::set((string) $k, $v);
            }
        });
    }

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return [
            // Token Identity & Economics
            'token_name'                 => 'یارکوین',
            'token_name_en'              => 'YarCoin',
            'token_symbol'               => 'YAR',
            'token_decimals_display'     => '4',
            'token_chain_id'             => '9898',
            'token_contract_virtual'     => '0x596172546F6B656E000000000000000000009898',
            'token_max_supply'           => '100000000.00000000', // 0 = unlimited
            'token_price_usd'            => '0.10',
            'token_price_irt'            => '8500',
            'transfers_paused'           => '0',
            'signup_welcome_tokens'      => '5.00000000',

            // Fee Engine (Admin-Controlled)
            'fee_mode'                   => 'hybrid',     // percentage | fixed | hybrid | zero
            'fee_bps'                    => '100',        // 100 bps = 1.00%
            'fee_fixed'                  => '0.25000000', // Fixed fee per transfer
            'fee_min'                    => '0.10000000',
            'fee_max'                    => '250.00000000',
            'fee_payer_mode'             => 'sender_extra', // sender_extra | deduct_from_amount
            'fee_burn_share_bps'         => '2000',       // 20% of fee is auto-burned (deflationary!)
            'vip_discount_bps'           => '5000',       // 50% fee discount for VIP accounts
            'group_tip_fee_bps'          => '0',          // Free or custom fee inside social groups!
            'min_transfer_amount'        => '0.01000000',
            'max_single_transfer'        => '500000.00000000',

            // OTP & Security Center
            'admin_auth_mode'            => 'password_and_otp', // password_and_otp | passwordless_otp | password_only
            'user_web_otp_required'      => '0',
            'otp_delivery_default'       => 'totp_or_messenger', // totp_or_messenger | bale | telegram | file
            'otp_digits'                 => '6',
            'otp_ttl_seconds'            => '180',
            'otp_max_attempts'           => '5',
            'otp_resend_cooldown'        => '45',

            // Lending Defaults
            'default_penalty_bps'        => '200',        // 2% late penalty
            'loan_reminder_hours_before' => '24',

            // Social Bots & Channels
            'bale_enabled'               => '1',
            'telegram_enabled'           => '1',
            'telegram_api_base_override' => '',           // Optional Cloudflare Worker / Bypass Proxy URL!
            'public_alert_bale_chat'     => '',           // Whale / Mint public announcement channel on Bale
            'public_alert_tg_chat'       => '',           // Whale / Mint public announcement channel on Telegram
            'whale_alert_threshold'      => '1000.00000000',
            'bot_welcome_text'           => '🌟 به کیف پول رسمی «{token_name} ({symbol})» خوش آمدید!\n\n💎 موجودی، انتقال سریع، دریافت وام و اتصال به اپلیکیشن اندروید کیف پول در اختیار شماست.',
        ];
    }
}
