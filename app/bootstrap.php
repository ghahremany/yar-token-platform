<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

date_default_timezone_set('Asia/Tehran');
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli' && !defined('YAR_STATELESS') && session_status() === PHP_SESSION_NONE) {
    $savePath = (string) ini_get('session.save_path');
    if ($savePath === '' || !is_dir($savePath) || !is_writable($savePath)) {
        $fallbackSess = root_path('storage/tmp/sessions');
        if (!is_dir($fallbackSess)) {
            @mkdir($fallbackSess, 0700, true);
        }
        session_save_path($fallbackSess);
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_name('YARTOKEN_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => $isHttps ? 'None' : 'Lax',
    ]);
    session_start();

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'self' 'unsafe-inline'; base-uri 'self'; form-action 'self'");
}
