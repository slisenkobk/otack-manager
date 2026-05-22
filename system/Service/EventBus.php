<?php
declare(strict_types=1);
namespace App\Service;

final class EventBus {
    private array $listeners = [];

    public function on(string $event, callable $fn): void
    {
        $this->listeners[$event][] = $fn;
    }

    public function fire(string $event, array $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $fn) {
            try {
                $fn($payload);
            } catch (\Throwable $e) {
                error_log("EventBus[$event]: " . $e->getMessage());
            }
        }
    }
}
