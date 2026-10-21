<?php
declare(strict_types=1);

namespace App;

final class RateLimiter
{
    public static function allow(string $bucket, int $maxHits, int $windowSeconds): bool
    {
        $key = substr(hash('sha256', $bucket), 0, 64);
        $now = time();
        try {
            $pdo = Db::pdo();
            $stmt = $pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            if (!$row || (int) $row['reset_at'] <= $now) {
                $up = $pdo->prepare(
                    'INSERT INTO rate_limits (rl_key, hits, reset_at) VALUES (?, 1, ?)
                     ON DUPLICATE KEY UPDATE hits = 1, reset_at = VALUES(reset_at)'
                );
                $up->execute([$key, $now + $windowSeconds]);
                return true;
            }
            if ((int) $row['hits'] >= $maxHits) {
                return false;
            }
            $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?')->execute([$key]);
            return true;
        } catch (\Throwable) {
            return true;
        }
    }
}
