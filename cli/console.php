<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require_once __DIR__ . '/../app/bootstrap.php';

use App\Db;
use App\Ledger;
use App\Otp;
use App\Settings;
use App\TokenEngine;
use App\Users;

$cmd = $argv[1] ?? 'help';

switch ($cmd) {
    case 'ledger:verify':
        $res = Ledger::verifyChainIntegrity();
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit($res['ok'] ? 0 : 1);

    case 'token:stats':
        echo json_encode(TokenEngine::supplyOverview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(0);

    case 'token:mint':
        $amount = $argv[2] ?? '';
        $target = isset($argv[3]) ? Users::resolveRecipient($argv[3]) : Users::systemAccount('treasury');
        if (!$target || $amount === '') {
            fwrite(STDERR, "Usage: php cli/console.php token:mint <amount> [target_wallet]\n");
            exit(1);
        }
        $tx = TokenEngine::mint((int) $target['id'], $amount, 1, 'CLI Mint');
        echo "Minted successfully: tx_hash={$tx['tx_hash']}\n";
        exit(0);

    case 'admin:reset-otp':
        $username = $argv[2] ?? 'admin';
        $st = Db::pdo()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $adm = $st->fetch();
        if (!$adm) {
            fwrite(STDERR, "Admin not found: {$username}\n");
            exit(1);
        }
        Settings::set('admin_auth_mode', 'password_and_otp');
        $codes = Otp::regenerateRecoveryCodes((int) $adm['id']);
        echo "Emergency Recovery Codes for '{$username}':\n" . implode("\n", $codes) . "\n";
        exit(0);

    default:
        echo "YarToken Console Commands:\n"
           . "  php cli/console.php ledger:verify                # Audit SHA-256 hash chain & supply conservation\n"
           . "  php cli/console.php token:stats                  # Show live supply, vaults & active holders\n"
           . "  php cli/console.php token:mint <amount> [target] # Mint new tokens to Treasury or target wallet\n"
           . "  php cli/console.php admin:reset-otp [username]   # Issue 8 fresh emergency recovery codes\n";
}
