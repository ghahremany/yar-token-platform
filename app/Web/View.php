<?php
declare(strict_types=1);

namespace App\Web;

use App\Settings;

final class View
{
    /**
     * Renders shared portal chrome for Admin ('admin'), Representative ('agent'), or Web Wallet ('wallet').
     *
     * @param array<int,array{route:string,label:string,icon:string,section?:string}> $navItems
     */
    public static function layout(
        string $portalTitle,
        string $pageTitle,
        string $activeRoute,
        array $navItems,
        string $bodyHtml,
        string $basePortalPath,
        string $rightTopHtml = ''
    ): void {
        $sym = is_installed() ? Settings::get('token_symbol', 'YAR') : 'YAR';
        $tokName = is_installed() ? Settings::get('token_name', 'یارکوین') : 'یارکوین';
        $flash = flash_pop();
        $assetsUrl = base_url('assets');
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($portalTitle) ?></title>
    <link rel="stylesheet" href="<?= e($assetsUrl) ?>/app.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-coin"><?= e(substr($sym, 0, 3)) ?></div>
            <div>
                <h1><?= e($tokName) ?> (<?= e($sym) ?>)</h1>
                <small><?= e($portalTitle) ?></small>
            </div>
        </div>
        <div style="padding: 8px 0; flex: 1;">
            <?php foreach ($navItems as $item): ?>
                <?php if (!empty($item['section'])): ?>
                    <div class="nav-section"><?= e($item['section']) ?></div>
                <?php endif; ?>
                <a class="nav-item <?= $activeRoute === $item['route'] ? 'active' : '' ?>"
                   href="<?= e(base_url($basePortalPath . '?r=' . $item['route'])) ?>">
                    <span><?= $item['icon'] ?></span>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div style="padding: 14px 16px; border-top: 1px solid rgba(255,255,255,0.08); font-size: 12px; color: #94a3b8;">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <a href="<?= e(base_url()) ?>" style="color:#94a3b8;">🌐 کاوشگر شبکه</a>
                <a href="<?= e(base_url('wallet/')) ?>" style="color:#94a3b8;">💼 وب‌کیف‌پول</a>
                <a href="<?= e(base_url('agent/')) ?>" style="color:#94a3b8;">🤝 نمایندگان</a>
            </div>
        </div>
    </aside>

    <div class="main-wrap">
        <header class="topbar">
            <h2 class="topbar-title"><?= e($pageTitle) ?></h2>
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <?= $rightTopHtml ?>
            </div>
        </header>

        <main class="content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
            <?= $bodyHtml ?>
        </main>
    </div>
</div>
<script src="<?= e($assetsUrl) ?>/app.js"></script>
</body>
</html>
        <?php
    }

    public static function authPage(string $title, string $innerHtml): void
    {
        $assetsUrl = base_url('assets');
        $flash = flash_pop();
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <link rel="stylesheet" href="<?= e($assetsUrl) ?>/app.css">
</head>
<body>
<div class="auth-shell">
    <div class="auth-box">
        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'success' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>
        <?= $innerHtml ?>
    </div>
</div>
<script src="<?= e($assetsUrl) ?>/app.js"></script>
</body>
</html>
        <?php
    }
}
