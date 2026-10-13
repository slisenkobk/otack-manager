<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Minimal Telegram Bot API client for the owner's task-selection digest.
 * The token is part of every URL, so nothing here may echo a URL: errors
 * carry Telegram's own description with the token masked.
 */
final class Telegram
{
    private string $base;

    public function __construct(private string $token, string $base = 'https://api.telegram.org', private int $timeout = 15)
    {
        if (!preg_match('/^\d{3,20}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new \InvalidArgumentException('OTACK_TG_BOT_TOKEN does not look like a Telegram bot token');
        }
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        if (!($scheme === 'https' || ($scheme === 'http' && $loopback))) {
            throw new \InvalidArgumentException('Telegram base URL must be https (http only on loopback, for tests)');
        }
        $this->base = rtrim($base, '/');
    }

    public function call(string $method, array $params = []): mixed
    {
        if (!preg_match('/^[a-zA-Z]{1,64}$/', $method)) {
            throw new \InvalidArgumentException('bad Telegram method name');
        }
        $ctx = stream_context_create(['http' => [
            'method'          => 'POST',
            'header'          => "Content-Type: application/json\r\n",
            'content'         => json_encode((object)$params, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'timeout'         => $this->timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
        ]]);
        // A failed request raises a warning whose text holds the full URL, token
        // included. Swallow it here so it reaches neither an outer error handler
        // nor error_get_last().
        set_error_handler(static fn(): bool => true);
        try {
            $raw = file_get_contents($this->base . '/bot' . $this->token . '/' . $method, false, $ctx);
        } finally {
            restore_error_handler();
        }
        if ($raw === false) {
            throw new \RuntimeException("Telegram $method: request failed (network)");
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || ($json['ok'] ?? false) !== true) {
            $desc = is_array($json) && is_string($json['description'] ?? null) ? $json['description'] : 'non-JSON response';
            throw new \RuntimeException("Telegram $method: " . str_replace($this->token, '***', $desc));
        }
        return $json['result'] ?? null;
    }
}
