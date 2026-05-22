<?php
declare(strict_types=1);
namespace App\Repository;

final class NotificationLogRepository {
    public function __construct(private \PDO $pdo) {}

    public function log(string $event, array $payload, bool $ok, ?string $error): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications_log (event, payload, ok, error, sent_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $event,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $ok ? 1 : 0,
            $error,
            (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z'),
        ]);
    }
}
