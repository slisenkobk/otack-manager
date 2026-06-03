<?php
declare(strict_types=1);
namespace App\Service;

class TelegramNotifier {
    public function __construct(private string $token, private string $chatId) {}

    /**
     * Send a Telegram message. $html is pre-built HTML — callers must escape user-supplied
     * values via self::escape(). Telegram HTML mode supports only &lt; &gt; &amp; &quot;
     * and the tags <b>, <i>, <u>, <s>, <code>, <pre>, <a href="…">.
     */
    public function send(string $html, ?string $url = null): array {
        if ($this->token === '' || $this->chatId === '') {
            return ['ok' => true, 'error' => 'skipped'];
        }
        $message = $html;
        // Telegram silently drops link entities whose href isn't an absolute http(s) URL —
        // treat anything else as "no URL" so the message at least delivers without weird
        // half-links.
        if ($url !== null && $url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = null;
        }
        if ($url) {
            $hrefSafe = self::escape($url, true);
            // Wrap the leading [TAG] (e.g. "[TASK]", "[PROJECT]") in the link. Falls back
            // to appending "Open" if the message doesn't start with a bracketed tag.
            $message = preg_replace_callback(
                '/^(\[[^\]]+\])/u',
                fn($m) => '<a href="' . $hrefSafe . '">' . $m[1] . '</a>',
                $message,
                1,
                $count
            );
            if (!$count) $message .= "\n\n<a href=\"" . $hrefSafe . "\">Open</a>";
        }
        $payload = [
            'chat_id' => $this->chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        $endpoint = 'https://api.telegram.org/bot' . $this->token . '/sendMessage';
        return $this->postWithRetry($endpoint, $payload, 1);
    }

    /**
     * Escape a user-supplied string for embedding in Telegram HTML mode. Pass $forAttr=true
     * when interpolating inside an attribute value (e.g. href="…") to also escape ".
     */
    public static function escape(string $s, bool $forAttr = false): string {
        $map = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
        if ($forAttr) $map['"'] = '&quot;';
        return strtr($s, $map);
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
