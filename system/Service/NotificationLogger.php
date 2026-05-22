<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\NotificationLogRepository;

final class NotificationLogger {
    public function __construct(private TelegramNotifier $notifier, private NotificationLogRepository $repo) {}

    public function notify(string $event, string $text, ?string $url, array $payload): void {
        $result = $this->notifier->send($text, $url);
        // Truncate payload before logging (don't log full comment bodies)
        $loggedPayload = $payload;
        if (isset($loggedPayload['body_text']) && is_string($loggedPayload['body_text'])) {
            $loggedPayload['body_text'] = mb_substr($loggedPayload['body_text'], 0, 200);
        }
        $this->repo->log($event, $loggedPayload, (bool)$result['ok'], $result['error']);
    }
}
