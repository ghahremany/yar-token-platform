<?php
declare(strict_types=1);

/**
 * فایل پیکربندی پایه برای هاست اشتراکی (در صورت عدم استفاده از .env)
 * توجه امنیتی: مقادیر حساس به‌صورت خودکار توسط install.php در فایل غیرقابل‌ردیابی .env ذخیره می‌شوند.
 */
return [
    'app_name'           => 'یارکوین | سامانه توکن و کیف پول هوشمند',
    'app_url'            => '',
    'app_env'            => 'production',
    'app_secret'         => '',
    'trust_proxy'        => false,

    'db_host'            => '127.0.0.1',
    'db_port'            => 3306,
    'db_name'            => '',
    'db_user'            => '',
    'db_pass'            => '',

    'bale_bot_token'     => '',
    'bale_api_base'      => 'https://tapi.bale.ai',
    'telegram_bot_token' => '',
    'telegram_api_base'  => 'https://api.telegram.org',
    'webhook_secret'     => '',
    'bridge_hmac_secret' => '',
    'cron_key'           => '',
];
