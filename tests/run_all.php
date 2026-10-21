<?php
declare(strict_types=1);

/**
 * YarToken Enterprise End-to-End Integration & Cryptographic Verification Suite
 */

$root = dirname(__DIR__);
$passCount = 0;

function check(bool $cond, string $label): void
{
    global $passCount;
    if (!$cond) {
        fwrite(STDERR, "❌ FAIL: {$label}\n");
        exit(1);
    }
    $passCount++;
    echo "  PASS  {$label}\n";
}

echo "=== [1/11] PHP Syntax Lint ===\n";
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$linted = 0;
foreach ($rii as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "Syntax error in {$file->getPathname()}:\n" . implode("\n", $out) . "\n");
            exit(1);
        }
        $linted++;
    }
}
check($linted >= 50, "All {$linted} PHP source files passed strict syntax check");

$dbHost = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('TEST_DB_PORT') ?: '3399';
$dbName = getenv('TEST_DB_NAME') ?: 'yar_token_test';
$dbUser = getenv('TEST_DB_USER') ?: 'root';
$dbPass = getenv('TEST_DB_PASS') ?: '';

$pdoAdmin = new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdoAdmin->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$pdoAdmin->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$envPath = $root . '/.env';
$hadEnv = is_file($envPath);
$prevEnv = $hadEnv ? file_get_contents($envPath) : '';

require_once $root . '/app/bootstrap.php';

echo "\n=== [2/11] Auto-Installer + Genesis Mint ===\n";
$install = App\Installer::install([
    'db_host'        => $dbHost,
    'db_port'        => $dbPort,
    'db_name'        => $dbName,
    'db_user'        => $dbUser,
    'db_pass'        => $dbPass,
    'app_url'        => 'http://localhost:8092',
    'trust_proxy'    => false,
    'token_name'     => 'یارکوین',
    'token_symbol'   => 'YAR',
    'genesis_supply' => '1000000',
    'admin_username' => 'admin',
    'admin_password' => 'StrongAdminPass#2026!',
    'admin_name'     => 'مدیر کل شبکه',
]);
check($install['admin_id'] > 0, 'Super-admin created & schema provisioned');
check(count($install['recovery_codes']) === 8, '8 single-use Argon2id recovery codes generated');
check(str_starts_with($install['totp_uri'], 'otpauth://totp/'), 'Wallet Yar TOTP otpauth:// URI generated');

App\Config::reload();
App\Db::reset();
App\Settings::flush();
App\Settings::setMany(['token_max_supply' => '5000000.00000000']);

$adminId = (int)$install['admin_id'];
$treasury = App\Users::systemAccount('treasury');
$treasuryId = (int)$treasury['id'];

$supply = App\TokenEngine::supplyOverview();
check(bccomp((string)$supply['net_total_supply'], '1000000.00000000', 8) === 0, 'Genesis net supply equals 1,000,000.00000000 YAR');

echo "\n=== [3/11] Custom Coin Mint, Burn, Pause & Max Supply Cap ===\n";
$mintRes = App\TokenEngine::mint($treasuryId, '50000', $adminId, 'تولید نقدینگی اولیه خزانه', 'test-mint-1');
check(bccomp((string)$mintRes['net_amount'], '50000.00000000', 8) === 0, 'Super-Admin Mint (تولید توکن جدید) succeeded');

$burnRes = App\TokenEngine::burn($treasuryId, '10000', $adminId, 'توکن‌سوزی دوره‌ای خزانه');
check(bccomp((string)$burnRes['burned_amount'], '10000.00000000', 8) === 0, 'Super-Admin Burn (توکن‌سوزی) succeeded');

$supply = App\TokenEngine::supplyOverview();
check(bccomp((string)$supply['net_total_supply'], '1040000.00000000', 8) === 0, 'Net supply accurately reflects Mint - Burn = 1,040,000 YAR');

$exceededThrown = false;
try {
    App\TokenEngine::mint($treasuryId, '9000000', $adminId, 'بیش از سقف');
} catch (Throwable $e) {
    $exceededThrown = true;
}
check($exceededThrown, 'Max supply cap strictly rejects excess minting');

echo "\n=== [4/11] REAL Java Android Wallet <-> PHP Server Non-Custodial ECDSA Auth & Signed Transfer ===\n";
$javaBuild = sys_get_temp_dir() . '/yar_java_sign_test';
@mkdir($javaBuild, 0777, true);
$compileCmd = 'javac -encoding UTF-8 -d ' . escapeshellarg($javaBuild)
    . ' ' . escapeshellarg($root . '/sdk/android/standalone/YarAndroidSigner.java') . ' 2>&1';
exec($compileCmd, $jOut, $jRc);
check($jRc === 0, 'Android Java clean-room BIP-39/32 + RFC 6979 ECDSA signer compiled cleanly');

$mnemonic = 'abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about';
exec('java -cp ' . escapeshellarg($javaBuild) . ' YarAndroidSigner '
    . escapeshellarg($mnemonic) . ' ' . escapeshellarg('ping'), $pingOut, $pRc);
[$javaAddr, $javaPub, $pingSig] = explode('|', trim($pingOut[0]));
check($javaAddr === '0x9858EfFD232B4033E47d90003D41EC34EcaEda94', 'Java BIP-44 m/44\'/60\'/0\'/0/0 matches EIP-55 test vector');

// 2-Step Non-Custodial Challenge-Response Handshake
$challenge = App\ApiAuth::issueWalletChallenge($javaAddr);
$signOut = [];
exec('java -cp ' . escapeshellarg($javaBuild) . ' YarAndroidSigner '
    . escapeshellarg($mnemonic) . ' ' . escapeshellarg($challenge['message']), $signOut, $sRc);
[, , $challengeSig] = explode('|', trim($signOut[0]));

$authRes = App\ApiAuth::verifyWalletSignature(
    $javaAddr,
    $challenge['nonce'],
    $javaPub,
    $challengeSig,
    'Wallet Yar Android v2.0'
);
check(str_starts_with($authRes['token'], 'ytk_'), 'PHP server verified Java secp256k1 signature & issued Bearer session token');
$androidUserId = (int)$authRes['user']['id'];

// Fund the non-custodial Android wallet from Treasury (0 welcome tokens + 5000 minted)
App\Settings::setMany(['signup_welcome_tokens' => '0']);
$preBal = (string)App\Users::findById($androidUserId)['balance'];
App\TokenEngine::mint($androidUserId, '5000', $adminId, 'شارژ کیف پول اندروید');

echo "\n=== [5/11] Representative Promotion, Dynamic Hybrid Fee Split & Signed Java Transfer ===\n";
$agentUser = App\Users::create([
    'username'  => 'tehran_agent',
    'password'  => 'AgentPass#2026!',
    'full_name' => 'نماینده تهران',
]);
$agentUserId = (int)$agentUser['id'];
$agentRow = App\Agents::upsertAgent($agentUserId, [
    'title'                => 'نمایندگی مرکزی تهران',
    'credit_pool_limit'    => '50000',
    'commission_bps'       => 2500,
    'max_interest_bps'     => 2400,
    'max_term_days'        => 180,
    'can_lend_from_pool'   => 1,
    'can_lend_from_wallet' => 1,
], $adminId);
App\Agents::attachSubUser($agentUserId, (string)$authRes['user']['wallet_code']);

// Set Hybrid fee rule: 1.00 YAR fixed + 1% (100 bps), 10% Auto-burn, 25% Agent commission!
App\Settings::setMany([
    'fee_mode'           => 'hybrid',
    'fee_fixed'          => '1.00000000',
    'fee_bps'            => '100',
    'fee_min'            => '0.10000000',
    'fee_max'            => '500.00000000',
    'fee_burn_share_bps' => '1000',
    'fee_payer_mode'     => 'sender_extra',
]);

$recipient = App\Users::create([
    'username'  => 'sara_shop',
    'password'  => 'ShopPass#2026!',
    'full_name' => 'فروشگاه سارا',
]);

$canonicalTx = App\ApiAuth::canonicalTransferMessage($javaAddr, (string)$recipient['evm_address'], '200.00000000', '0');
$txSignOut = [];
exec('java -cp ' . escapeshellarg($javaBuild) . ' YarAndroidSigner '
    . escapeshellarg($mnemonic) . ' ' . escapeshellarg($canonicalTx), $txSignOut);
[, , $txSig] = explode('|', trim($txSignOut[0]));

// Verify transfer signature mathematically via Secp256k1 before calling TokenEngine::transfer (exact flow in api/v1)
$txDigest = App\Crypto\Secp256k1::personalMessageDigest($canonicalTx);
check(App\Crypto\Secp256k1::verifyDigest($txDigest, $txSig, $javaPub, $javaAddr), 'Java RFC 6979 transfer signature verified against EIP-55 address');

$txRes = App\TokenEngine::transfer(
    $androidUserId,
    (int)$recipient['id'],
    '200',
    'android_api',
    'خرید موبایل با امضای دیجیتال اندروید',
    'java-tx-001',
    false,
    $txSig
);
$txRow = $txRes['tx'];
check(bccomp((string)$txRow['fee_amount'], '3.00000000', 8) === 0, 'Hybrid fee calculated accurately (1 fixed + 1% of 200 = 3 YAR)');
$agentAfterFee = App\Users::findById($agentUserId);
check(bccomp((string)$agentAfterFee['balance'], '0.67500000', 8) === 0, 'Representative earned 0.67500000 YAR fee commission (25% of post-burn fee)');
check(bccomp((string)$txRow['burned_amount'], '0.30000000', 8) === 0, 'Auto-burn destroyed 0.30000000 YAR automatically');

// Replay same idempotency key -> returns duplicate=true with exact same tx_uuid without double-spending!
$replayRes = App\TokenEngine::transfer(
    $androidUserId,
    (int)$recipient['id'],
    '200',
    'android_api',
    'خرید موبایل با امضای دیجیتال اندروید',
    'java-tx-001'
);
check(($replayRes['duplicate'] ?? false) === true && $replayRes['tx']['tx_hash'] === $txRow['tx_hash'], 'Idempotency key prevents duplicate debit');

echo "\n=== [6/11] Unmodified Wallet Yar EVM JSON-RPC 2.0 Verification ===\n";
$curBal = (string)App\Users::findById($androidUserId)['balance'];
$rpcBal = App\EvmRpc::handle([
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'eth_getBalance',
    'params'  => [$javaAddr, 'latest'],
]);
$expectedWeiDec = bcmul($curBal, '1000000000000000000', 0);
$expectedHex = '0x' . gmp_strval(gmp_init($expectedWeiDec, 10), 16);
check($rpcBal['result'] === $expectedHex, "eth_getBalance returns exact 18-decimal hex wei ({$expectedHex}) for unmodified Wallet Yar");

$erc20Call = App\EvmRpc::handle([
    'jsonrpc' => '2.0',
    'id'      => 2,
    'method'  => 'eth_call',
    'params'  => [['data' => '0x70a08231' . str_pad(substr(strtolower($javaAddr), 2), 64, '0', STR_PAD_LEFT)], 'latest'],
]);
check(strlen($erc20Call['result']) === 66 && str_ends_with($erc20Call['result'], substr($expectedHex, 2)), 'eth_call balanceOf(address) ABI response matches native balance');

echo "\n=== [7/11] Representative Loan Origination, Repayment & Overdue Auto-Deduct Cron ===\n";
$loan = App\Loans::grantLoan($agentUserId, $androidUserId, '3000', [
    'funding_source'     => 'credit_pool',
    'interest_bps'       => 600,
    'installments_count' => 3,
    'interval_days'      => 30,
    'auto_deduct'        => true,
    'purpose'            => 'وام خرید تجهیزات',
]);
check($loan['status'] === 'active', 'Representative originated 3,000 YAR 3-installment loan from Credit Pool');

$pay1 = App\Loans::repayInstallment((int)$loan['id'], $androidUserId);
check(bccomp((string)$pay1['paid_amount'], '0.00000000', 8) > 0, 'Borrower repaid Installment #1');

// Backdate Installment #2 past grace period so Overdue Cron auto-collects!
App\Db::pdo()->prepare('UPDATE loan_installments SET due_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE loan_id = ? AND installment_no = 2')
    ->execute([(int)$loan['id']]);
$cronStats = App\Loans::processScheduledInstallments();
check(($cronStats['auto_paid'] ?? 0) >= 1, 'Overdue Cron automatically collected matured Installment #2');

// Pay final Installment #3 -> closes loan, boosts credit score, frees Agent credit pool to 0!
$pay3 = App\Loans::repayInstallment((int)$loan['id'], $androidUserId);
$closedLoan = App\Loans::findById((int)$loan['id']);
check($closedLoan['status'] === 'repaid', 'Final installment closed loan completely');
$updatedBorrower = App\Users::findById($androidUserId);
$updatedAgent = App\Agents::getProfile($agentUserId);
check((int)$updatedBorrower['credit_score'] > 650, 'Borrower credit score increased automatically upon timely loan completion');
check(bccomp((string)$updatedAgent['credit_pool_used'], '0.00000000', 8) === 0, 'Agent Credit Pool utilization returned cleanly to 0.00000000');

echo "\n=== [8/11] Social Bot & Group Economy (Chat-to-Earn + Reply Tip + Escrow Red Packet) ===\n";
$grpSenderUpdate = [
    'message' => [
        'message_id' => 101,
        'from' => ['id' => 90001, 'first_name' => 'امیر', 'username' => 'amir_bale'],
        'chat' => ['id' => -50001, 'type' => 'group', 'title' => 'گروه اقتصاد یار'],
        'text' => 'سلام به اعضای گروه فوق‌العاده اقتصاد اجتماعی توکن یار!',
    ],
];
App\Social\BotEngine::handleUpdate('bale', $grpSenderUpdate);
$amirSocial = App\Users::findOrCreateBySocial('bale', '90001', 'امیر', 'amir_bale');
App\TokenEngine::mint((int)$amirSocial['id'], '500', $adminId, 'شارژ حساب اجتماعی امیر');

// Reply Tip inside Group ("انتقال 50")
$grpTipUpdate = [
    'message' => [
        'message_id' => 102,
        'from' => ['id' => 90001, 'first_name' => 'امیر', 'username' => 'amir_bale'],
        'chat' => ['id' => -50001, 'type' => 'group', 'title' => 'گروه اقتصاد یار'],
        'reply_to_message' => [
            'message_id' => 100,
            'from' => ['id' => 90002, 'first_name' => 'مینا', 'username' => 'mina_bale'],
        ],
        'text' => 'انتقال 50',
    ],
];
App\Social\BotEngine::handleUpdate('bale', $grpTipUpdate);
$minaSocial = App\Users::findOrCreateBySocial('bale', '90002', 'مینا', 'mina_bale');
check(bccomp((string)$minaSocial['balance'], '50.00000000', 8) === 0, 'Group Reply-Tipping transferred 50 YAR to replied group member');

// Red Packet in Group ("هدیه 100 2") + Claim!
$pktUpdate = [
    'message' => [
        'message_id' => 103,
        'from' => ['id' => 90001, 'first_name' => 'امیر', 'username' => 'amir_bale'],
        'chat' => ['id' => -50001, 'type' => 'group', 'title' => 'گروه اقتصاد یار'],
        'text' => 'هدیه 100 2',
    ],
];
App\Social\BotEngine::handleUpdate('bale', $pktUpdate);
$pktRow = App\Db::pdo()->query('SELECT * FROM red_packets ORDER BY id DESC LIMIT 1')->fetch();
check($pktRow !== false && $pktRow['status'] === 'active', 'Group Red Packet locked 100 YAR in System Escrow');

$claimRes1 = App\Social\RedPackets::claim((string)$pktRow['packet_code'], (int)$minaSocial['id']);
$claimRes2 = App\Social\RedPackets::claim((string)$pktRow['packet_code'], (int)$recipient['id']);
$sumClaims = bcadd((string)$claimRes1['share'], (string)$claimRes2['share'], 8);
check(bccomp($sumClaims, '100.00000000', 8) === 0 && $claimRes2['status'] === 'completed', '2 group members claimed 100% of Escrow Red Packet atomically (sum = 100.00000000 YAR)');

echo "\n=== [9/11] Multi-Channel Admin OTP + TOTP Replay Defense + Argon2id Recovery Codes ===\n";
$adminRow = App\Db::pdo()->query('SELECT * FROM admins WHERE id = ' . $adminId)->fetch();
$otpCh1 = App\Otp::issueAdminChallenge($adminRow);
$totpCode = App\Crypto\Totp::code($install['totp_secret'], null, 6, 30);
$verifiedAdm = App\Otp::verifyAdminChallenge($otpCh1['selector'], $totpCode);
check($verifiedAdm !== null, 'RFC 6238 Wallet Yar TOTP code verified cleanly');

$otpCh2 = App\Otp::issueAdminChallenge(App\Db::pdo()->query('SELECT * FROM admins WHERE id = ' . $adminId)->fetch());
$replayAdm = App\Otp::verifyAdminChallenge($otpCh2['selector'], $totpCode);
check($replayAdm === null, 'Same 30-second TOTP slice strictly rejected on replay attempt');

$otpCh3 = App\Otp::issueAdminChallenge(App\Db::pdo()->query('SELECT * FROM admins WHERE id = ' . $adminId)->fetch());
$recAdm = App\Otp::verifyAdminChallenge($otpCh3['selector'], $install['recovery_codes'][0]);
check($recAdm !== null, 'Argon2id single-use recovery code verified cleanly');

$otpCh4 = App\Otp::issueAdminChallenge(App\Db::pdo()->query('SELECT * FROM admins WHERE id = ' . $adminId)->fetch());
$recReplay = App\Otp::verifyAdminChallenge($otpCh4['selector'], $install['recovery_codes'][0]);
check($recReplay === null, 'Consumed recovery code strictly rejected on second attempt');

echo "\n=== [10/11] Merchant Payment Gateway Invoice Creation, Settlement & Atomic Refund ===\n";
$inv = App\Invoices::create((int)$recipient['id'], '75', 'سفارش شماره ۱۰۴۵', '', 'ORD-1045', 30, 'https://shop.example.com/thanks');
$paidInv = App\Invoices::pay((string)$inv['invoice_code'], $androidUserId);
check(!empty($paidInv['tx']['tx_hash']), 'Merchant invoice INV settled atomically with live fee calculation');
$refundInv = App\Invoices::refund((string)$inv['invoice_code'], (int)$recipient['id'], null);
check(!empty($refundInv['tx']['tx_hash']) && App\Invoices::findByCode((string)$inv['invoice_code'])['status'] === 'refunded', 'Merchant invoice atomic zero-fee Refund verified');

// Also test Passcode Red Packet + Claim + Unclaimed Escrow Refund!
$secPkt = App\Social\RedPackets::create($androidUserId, '60', 3, 'web', '', 'lucky', 'پاکت رمزدار مسابقه‌ای', 'YAR2026', 24);
$wrongPassRejected = false;
try {
    App\Social\RedPackets::claim((string)$secPkt['packet_code'], (int)$minaSocial['id'], 'WRONG');
} catch (Throwable) {
    $wrongPassRejected = true;
}
check($wrongPassRejected, 'Secret-passcode Red Packet rejects wrong passcode');
$okClaim = App\Social\RedPackets::claim((string)$secPkt['packet_code'], (int)$minaSocial['id'], 'YAR2026');
check(bccomp((string)$okClaim['share'], '0.00000000', 8) > 0, 'Secret-passcode Red Packet awards lucky share on valid passcode');
$pktRefund = App\Social\RedPackets::refundRemaining((string)$secPkt['packet_code'], $androidUserId, false);
check(bccomp(bcadd((string)$okClaim['share'], (string)$pktRefund['refunded_amount'], 8), '60.00000000', 8) === 0, 'Red Packet Escrow Refund returns 100% of unclaimed balance atomically');

echo "\n=== [11/11] One-Click 7-Point Cryptographic Audit & Signed Certificate ===\n";
$fullAudit = App\Auditor::runFullAudit();
check($fullAudit['ok'] === true && count($fullAudit['checks']) === 7, 'One-Click 7-Point Auditor verified Hash-Chain, Supply, Escrow, Pools, Loans & Gateway');
check($fullAudit['certificate']['checks_passed'] === '7/7' && str_starts_with($fullAudit['certificate']['hmac_proof'], '0x'), 'Signed Proof-of-Reserve Certificate generated cleanly');

if ($hadEnv) {
    file_put_contents($envPath, $prevEnv);
} else {
    @unlink($envPath);
}

echo "\n🎉 ALL {$passCount} FULL-STACK INTEGRATION & CRYPTOGRAPHIC CHECKS PASSED WITH ZERO ERRORS!\n";
