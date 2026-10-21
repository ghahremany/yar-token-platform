<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    /** @param array<string,mixed> $context */
    public static function record(
        string $actorType,
        ?int $actorId,
        string $event,
        string $summary,
        array $context = []
    ): void {
        try {
            $stmt = Db::pdo()->prepare(
                'INSERT INTO audit_logs (actor_type, actor_id, event, summary, ip_address, context_json)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $actorType,
                $actorId,
                substr($event, 0, 80),
                mb_substr($summary, 0, 250),
                IpGuard::clientIp(),
                $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('audit_write_failed', ['err' => $e->getMessage()]);
        }
    }
}
