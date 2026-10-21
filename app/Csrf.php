<?php
declare(strict_types=1);

namespace App;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(?string $submitted = null): bool
    {
        $tok = $submitted ?? (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        return $expected !== '' && $tok !== '' && hash_equals($expected, $tok);
    }

    public static function assertPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        if (!self::verify()) {
            http_response_code(419);
            exit('نشست امنیتی منقضی شده یا توکن CSRF نامعتبر است. لطفاً صفحه را بازخوانی کنید.');
        }
    }
}
