<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\NotificationLogRepository;

final class NotificationLogger {
    /** @var list<array{event:string,text:string,url:?string,payload:array}> */
    private array $queue = [];
    private bool $shutdownRegistered = false;

    public function __construct(private TelegramNotifier $notifier, private NotificationLogRepository $repo) {}

    public function notify(string $event, string $text, ?string $url, array $payload): void {
        // Truncate payload before logging (don't log full comment bodies)
        if (isset($payload['body_text']) && is_string($payload['body_text'])) {
            $payload['body_text'] = mb_substr($payload['body_text'], 0, 200);
        }
        $this->queue[] = ['event' => $event, 'text' => $text, 'url' => $url, 'payload' => $payload];
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(fn() => $this->flush());
        }
    }

    /**
     * Drains the queue. Called automatically on shutdown after the HTTP response is closed
     * (via fastcgi_finish_request under PHP-FPM). Safe to call multiple times.
     */
    public function flush(): void {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        while ($this->queue) {
            $item = array_shift($this->queue);
            try {
                $result = $this->notifier->send($item['text'], $item['url']);
                $this->repo->log($item['event'], $item['payload'], (bool)$result['ok'], $result['error']);
            } catch (\Throwable $e) {
                // Never crash shutdown — log the failure and move on.
                try {
                    $this->repo->log($item['event'], $item['payload'], false, $e->getMessage());
                } catch (\Throwable) { /* swallow */ }
            }
        }
    }
}
