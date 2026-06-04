<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Persistent overlay over $_ENV / .env. Lives at data/config.json (mode
 * 0600). Read on every boot through Container::register(); written by
 * the install wizard and Platform Settings tab.
 *
 * Precedence: ConfigStore values win over .env. Keys must be on
 * ALLOWED_KEYS — anything else is rejected on set() AND silently dropped
 * on load(), so the surface cannot be expanded via a tampered
 * config.json file. Values are also re-validated on load() (bad values
 * are skipped, not thrown — boot must never crash on a malformed
 * config). Mode != 0600 on read triggers a non-fatal log warning.
 */
final class ConfigStore
{
    public const ALLOWED_KEYS = [
        'DB_DSN', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET', 'DB_COLLATION',
        'APP_URL', 'APP_SECRET', 'LOGIN_HASH',
        'TG_BOT_TOKEN', 'TG_CHAT_ID',
        'TRUSTED_PROXIES',
        'UPDATE_ENABLED', 'UPDATE_CHECK_INTERVAL', 'UPDATE_BACKUP_KEEP',
    ];

    public function __construct(private string $path = '')
    {
        if ($this->path === '') {
            $this->path = APP_ROOT . '/data/config.json';
        }
    }

    public function exists(): bool { return is_file($this->path); }

    /** @return array<string, string> */
    public function load(): array
    {
        if (!is_file($this->path)) return [];

        // Non-fatal mode audit. Shared-FS deployments may need a different
        // mode — warn but don't reject.
        $mode = fileperms($this->path) & 0777;
        if ($mode !== 0600 && class_exists(\App\Service\Log::class)) {
            try {
                \App\Service\Log::error('config', sprintf(
                    '%s has mode %04o, expected 0600',
                    $this->path,
                    $mode
                ));
            } catch (\Throwable) { /* never break boot on logging */ }
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') return [];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) continue;
            try {
                // Re-validate values so a hand-tampered config.json with a
                // malformed DB_DSN / APP_URL / etc. does NOT leak into $_ENV
                // on boot. Bad values are dropped silently — safer than
                // throwing during the boot prelude, which would 500 the
                // whole app on a typo.
                $out[$k] = self::validate($k, $v);
            } catch (\InvalidArgumentException) {
                // skip
            }
        }
        return $out;
    }

    public function get(string $key): ?string
    {
        $all = $this->load();
        return $all[$key] ?? null;
    }

    /** @param array<string, string|int|bool> $kv */
    public function set(array $kv): void
    {
        $current = $this->load();
        foreach ($kv as $k => $v) {
            if (!in_array($k, self::ALLOWED_KEYS, true)) {
                throw new \InvalidArgumentException("ConfigStore: key not in allow-list: $k");
            }
            $current[$k] = self::validate($k, $v);
        }
        $this->write($current);
    }

    public function unset(array $keys): void
    {
        $current = $this->load();
        foreach ($keys as $k) {
            unset($current[$k]);
        }
        $this->write($current);
    }

    private static function validate(string $key, mixed $value): string
    {
        if (is_bool($value)) $value = $value ? 'true' : 'false';
        elseif (is_int($value) || is_float($value)) $value = (string)$value;
        elseif (!is_string($value)) {
            throw new \InvalidArgumentException("ConfigStore: $key must be scalar");
        }
        $value = (string)$value;
        switch ($key) {
            case 'DB_DSN':
                if (!preg_match('/^(sqlite|mysql):/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: DB_DSN must start with sqlite: or mysql:");
                }
                break;
            case 'APP_URL':
                $ok = filter_var($value, FILTER_VALIDATE_URL) !== false;
                $ok = $ok && preg_match('#^https?://#', $value) === 1;
                if (!$ok) throw new \InvalidArgumentException("ConfigStore: APP_URL must be a valid http(s) URL");
                break;
            case 'TG_CHAT_ID':
                if ($value !== '' && !preg_match('/^-?\d+$/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: TG_CHAT_ID must be a numeric Telegram chat id");
                }
                break;
            case 'TRUSTED_PROXIES':
                if ($value !== '') {
                    foreach (explode(',', $value) as $hop) {
                        $hop = trim($hop);
                        if ($hop === '') continue;
                        if (!preg_match('#^[0-9a-fA-F:.]+(/\d{1,3})?$#', $hop)) {
                            throw new \InvalidArgumentException("ConfigStore: TRUSTED_PROXIES entry malformed: $hop");
                        }
                    }
                }
                break;
            case 'UPDATE_CHECK_INTERVAL':
            case 'UPDATE_BACKUP_KEEP':
                if (!preg_match('/^\d+$/', $value)) {
                    throw new \InvalidArgumentException("ConfigStore: $key must be a non-negative integer");
                }
                break;
            case 'UPDATE_ENABLED':
                if (!in_array($value, ['true', 'false'], true)) {
                    throw new \InvalidArgumentException("ConfigStore: UPDATE_ENABLED must be 'true' or 'false'");
                }
                break;
        }
        return $value;
    }

    private function write(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_writable($dir)) {
            throw new \RuntimeException("ConfigStore: $dir is not writable");
        }
        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(8));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("ConfigStore: failed to encode config");
        }
        if (file_put_contents($tmp, $json) === false) {
            throw new \RuntimeException("ConfigStore: failed to write $tmp");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException("ConfigStore: failed to rename $tmp to {$this->path}");
        }
    }
}
