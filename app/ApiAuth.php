<?php
declare(strict_types=1);

namespace App;

use App\Crypto\Keccak256;
use App\Crypto\Secp256k1;

/**
 * Cryptographic Non-Custodial Wallet Authentication (Sign-In With Android Wallet Yar secp256k1 ECDSA)
 * + Bearer API Session Tokens for Mobile & Merchant integrations.
 */
final class ApiAuth
{
    public static function canonicalAuthMessage(string $address, string $nonce): string
    {
        return "YarToken Wallet Authentication\nAddress: " . strtolower($address) . "\nNonce: {$nonce}";
    }

    public static function canonicalTransferMessage(string $fromAddress, string $toTarget, string $amountNorm, string $nonce): string
    {
        return "YarToken Signed Transfer\nFrom: " . strtolower($fromAddress) . "\nTo: " . strtolower(trim($toTarget)) . "\nAmount: {$amountNorm}\nNonce: {$nonce}";
    }

    /** @return array{nonce:string,message:string,expires_in:int} */
    public static function issueWalletChallenge(string $rawAddress): array
    {
        if (!Keccak256::isValidAddress($rawAddress)) {
            throw new \InvalidArgumentException('آدرس کیف پول معتبر نیست (فرمت 0x... مورد نیاز است).');
        }
        $addr = Keccak256::checksumAddress($rawAddress);
        $nonce = bin2hex(random_bytes(24));
        $ttl = 300;

        Db::pdo()->prepare(
            'INSERT INTO auth_nonces (nonce, evm_address, purpose, ip_address, expires_at)
             VALUES (?, ?, "wallet_auth", ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$nonce, $addr, IpGuard::clientIp(), $ttl]);

        return [
            'nonce'      => $nonce,
            'address'    => $addr,
            'message'    => self::canonicalAuthMessage($addr, $nonce),
            'expires_in' => $ttl,
        ];
    }

    /**
     * Mathematically verifies the Android Wallet's secp256k1 ECDSA signature over the single-use challenge nonce.
     * Auto-registers the non-custodial 0x... wallet on first connection and issues a Bearer session token!
     *
     * @return array{token:string,user:array<string,mixed>,expires_in:int}
     */
    public static function verifyWalletSignature(
        string $rawAddress,
        string $nonce,
        string $publicKeyHex,
        string $signatureHex,
        string $deviceName = 'Wallet Yar Android',
        ?string $agentInviteCode = null
    ): array {
        $addr = Keccak256::checksumAddress($rawAddress);
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT * FROM auth_nonces
             WHERE nonce = ? AND LOWER(evm_address) = LOWER(?) AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$nonce, $addr]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException('چالش امضای یکبار مصرف نامعتبر یا منقضی شده است.');
        }

        $msg = self::canonicalAuthMessage($addr, $nonce);
        $digest = Secp256k1::personalMessageDigest($msg);
        if (!Secp256k1::verifyDigest($digest, $signatureHex, $publicKeyHex, $addr)) {
            throw new \RuntimeException('امضای رمزنگاری کلید خصوصی با آدرس کیف پول مطابقت ندارد.');
        }

        $pdo->prepare('UPDATE auth_nonces SET used_at = NOW() WHERE nonce = ?')->execute([$nonce]);

        $user = Users::resolveRecipient($addr);
        if (!$user) {
            $parentAgent = null;
            if ($agentInviteCode !== null && trim($agentInviteCode) !== '') {
                $ag = Agents::findByCode($agentInviteCode);
                if ($ag) $parentAgent = (int) $ag['user_id'];
            }
            $user = Users::create([
                'evm_address'     => $addr,
                'public_key_hex'  => $publicKeyHex,
                'full_name'       => 'کیف پول ' . short_addr($addr),
                'parent_agent_id' => $parentAgent,
            ]);
        } elseif (empty($user['public_key_hex'])) {
            $pdo->prepare('UPDATE users SET public_key_hex = ? WHERE id = ?')->execute([$publicKeyHex, (int) $user['id']]);
        }

        if ((int) $user['is_frozen'] === 1) {
            throw new \RuntimeException('این کیف پول مسدود شده است.');
        }

        return self::issueBearerToken((int) $user['id'], $deviceName);
    }

    /** @return array{token:string,user:array<string,mixed>,expires_in:int} */
    public static function issueBearerToken(int $userId, string $deviceName = 'Mobile Client', int $ttlDays = 30): array
    {
        $raw = 'ytk_' . bin2hex(random_bytes(28));
        $prefix = substr($raw, 0, 12);
        $hash = hash('sha256', $raw);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO api_tokens (user_id, token_prefix, token_hash, device_name, last_ip, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))'
        );
        $stmt->execute([$userId, $prefix, $hash, mb_substr($deviceName, 0, 120), IpGuard::clientIp(), $ttlDays]);

        return [
            'token'      => $raw,
            'user'       => Users::findById($userId) ?? [],
            'expires_in' => $ttlDays * 86400,
        ];
    }

    public static function authenticateRequest(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return null;
        }
        $hash = hash('sha256', $m[1]);
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute([$hash]);
        $tok = $stmt->fetch();
        if (!$tok) {
            return null;
        }
        $user = Users::findById((int) $tok['user_id']);
        if (!$user || (int) $user['is_frozen'] === 1) {
            return null;
        }
        $pdo->prepare('UPDATE api_tokens SET last_used_at = NOW(), last_ip = ? WHERE id = ?')
            ->execute([IpGuard::clientIp(), (int) $tok['id']]);
        return $user;
    }

    public static function requireUser(): array
    {
        $u = self::authenticateRequest();
        if (!$u) {
            json_response(['ok' => false, 'error' => 'احراز هویت الزامی است (Invalid or expired Bearer token).'], 401);
        }
        return $u;
    }
}
