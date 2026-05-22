<?php
declare(strict_types=1);
namespace App\Service;

final class TelegramNotifier {
    public function __construct(private string $token, private string $chatId) {}

    public function send(string $text, ?string $url = null): array {
        if ($this->token === '' || $this->chatId === '') {
            return ['ok' => true, 'error' => 'skipped'];
        }
        $message = $text;
        if ($url) $message .= "\n\n<a href=\"" . htmlspecialchars($url) . "\">Open</a>";
        $payload = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        $endpoint = 'https://api.telegram.org/bot' . $this->token . '/sendMessage';
        return $this->postWithRetry($endpoint, $payload, 1);
    }

    private function postWithRetry(string $endpoint, array $payload, int $retries): array {
        $attempts = 1 + max(0, $retries);
        $lastError = null;
        for ($i = 0; $i < $attempts; $i++) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FAILONERROR => false,
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($err) { $lastError = $err; continue; }
            if ($code >= 200 && $code < 300) {
                $data = json_decode((string)$body, true);
                if ($data && ($data['ok'] ?? false)) return ['ok' => true, 'error' => null];
                $lastError = $data['description'] ?? 'Telegram API error';
            } else {
                $lastError = 'HTTP ' . $code;
            }
        }
        return ['ok' => false, 'error' => $lastError ?? 'unknown'];
    }
}
