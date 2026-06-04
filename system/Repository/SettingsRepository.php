<?php
declare(strict_types=1);
namespace App\Repository;

/**
 * Simple key/value store backed by the `settings` table. Used for admin
 * configurable values: timezone, contact info, form footer defaults.
 *
 * Keys are free-form strings; the controller layer decides which keys exist
 * and what they mean. Repository keeps no schema.
 */
final class SettingsRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Backtick `key` (reserved word in MySQL — works in SQLite too) so the
     * same SQL is valid on both drivers.
     */
    public function get(string $key, string $default = ''): string {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : (string)$v;
    }

    /** @return array<string, string> */
    public function getMany(array $keys, array $defaults = []): array {
        if (!$keys) return [];
        $place = implode(',', array_fill(0, count($keys), '?'));
        $stmt  = $this->pdo->prepare("SELECT `key`, value FROM settings WHERE `key` IN ($place)");
        $stmt->execute($keys);
        $out = [];
        foreach ($keys as $k) { $out[$k] = $defaults[$k] ?? ''; }
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[$r['key']] = (string)$r['value'];
        }
        return $out;
    }

    public function set(string $key, string $value): void {
        $this->pdo->prepare($this->upsertSql())->execute([$key, $value]);
    }

    public function setMany(array $pairs): void {
        if (!$pairs) return;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare($this->upsertSql());
            foreach ($pairs as $k => $v) { $stmt->execute([(string)$k, (string)$v]); }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cross-driver UPSERT. SQLite uses `ON CONFLICT … DO UPDATE`; MySQL 8 uses
     * `ON DUPLICATE KEY UPDATE … VALUES(value)` (works on 8.0.x without the
     * newer row-alias syntax). Same payload shape: two positional `?` placeholders.
     */
    private function upsertSql(): string
    {
        $driver = \App\Database\Connection::driverFor($this->pdo)?->name() ?? 'sqlite';
        if ($driver === 'mysql') {
            return 'INSERT INTO settings (`key`, value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)';
        }
        return 'INSERT INTO settings (`key`, value) VALUES (?, ?)
                ON CONFLICT(`key`) DO UPDATE SET value = excluded.value';
    }
}
