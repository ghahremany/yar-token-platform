<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    public static function admin(): ?array
    {
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $stmt = Db::pdo()->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            unset($_SESSION['admin_id']);
            return null;
        }
        return $row;
    }

    public static function requireAdmin(): array
    {
        $admin = self::admin();
        if (!$admin) {
            redirect_to(base_url('admin/?r=login'));
        }
        return $admin;
    }

    public static function loginAdminSession(array $admin): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['admin_id'] = (int) $admin['id'];
        unset($_SESSION['pending_admin_otp']);
        Db::pdo()->prepare('UPDATE admins SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
            ->execute([IpGuard::clientIp(), (int) $admin['id']]);
        Audit::record('admin', (int) $admin['id'], 'admin.login', "ورود موفق مدیر «{$admin['username']}» به پنل");
    }

    public static function logoutAdmin(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['pending_admin_otp']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function user(): ?array
    {
        $id = (int) ($_SESSION['wallet_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $u = Users::findById($id);
        if (!$u || (int) $u['is_frozen'] === 1) {
            unset($_SESSION['wallet_user_id']);
            return null;
        }
        return $u;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if (!$u) {
            redirect_to(base_url('wallet/?r=login'));
        }
        return $u;
    }

    public static function requireAgent(): array
    {
        $u = self::user();
        if (!$u) {
            redirect_to(base_url('agent/?r=login'));
        }
        $agent = Agents::getProfile((int) $u['id']);
        if (!$agent || (int) $agent['is_active'] !== 1) {
            http_response_code(403);
            exit('حساب کاربری شما دارای مجوز نمایندگی فعال نیست.');
        }
        return ['user' => $u, 'agent' => $agent];
    }

    public static function loginUserSession(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['wallet_user_id'] = (int) $user['id'];
        unset($_SESSION['pending_user_otp']);
        Db::pdo()->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    }

    public static function logoutUser(): void
    {
        unset($_SESSION['wallet_user_id'], $_SESSION['pending_user_otp']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
