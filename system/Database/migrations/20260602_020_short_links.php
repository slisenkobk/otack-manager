<?php
declare(strict_types=1);

// Short-link proxy with per-visit stats:
//   short_links        — slug → target_url mapping; admin/manager-owned.
//   short_link_visits  — one row per /s/{slug} hit. ip_hash is HMAC-derived so
//                        unique-visitor counts work without storing raw IPs.
return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS short_links (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            slug        TEXT NOT NULL UNIQUE,
            target_url  TEXT NOT NULL,
            title       TEXT NULL,
            is_disabled INTEGER NOT NULL DEFAULT 0,
            created_by  INTEGER NOT NULL,
            created_at  TEXT NOT NULL,
            updated_at  TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_short_links_created_by ON short_links(created_by)');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS short_link_visits (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            short_link_id  INTEGER NOT NULL,
            ip_hash        TEXT NULL,
            user_agent     TEXT NULL,
            referer        TEXT NULL,
            created_at     TEXT NOT NULL,
            FOREIGN KEY (short_link_id) REFERENCES short_links(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_short_link_visits_link_created ON short_link_visits(short_link_id, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_short_link_visits_link_hash    ON short_link_visits(short_link_id, ip_hash)');
};
