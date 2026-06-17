#!/usr/bin/env php
<?php
declare(strict_types=1);

// CLI re-seeder for the Knowledge «Welcome» pages (en/uk/pl). Useful when
// the migration was skipped (no admin existed yet) or when an operator wants
// to refresh the seed bodies after editing the source .md files.
//
// Behaviour:
//   • Skips any of the three seed slugs that already exist (idempotent).
//   • Creates the «Onboarding» category if missing.
//   • Fails if no approved admin exists yet — no author to attribute to.
//
// Run:
//   php bin/seed-welcome-doc.php          → only insert missing ones
//   php bin/seed-welcome-doc.php --force  → overwrite body_md/title for the
//                                           three known slugs (does NOT touch
//                                           tags/comments/snapshots)

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "bin/seed-welcome-doc.php must be run from the CLI\n");
    exit(1);
}

require dirname(__DIR__) . '/system/bootstrap.php';

use App\Database\Connection;

$force = in_array('--force', array_slice($argv, 1), true);

try {
    $pdo = Connection::openFromEnv();

    $admin = $pdo->query(
        "SELECT id FROM users WHERE role='admin' AND status='approved' ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if (!$admin) {
        fwrite(STDERR, "No approved admin found. Approve at least one admin first.\n");
        exit(1);
    }
    $authorId = (int)$admin;

    $stmt = $pdo->prepare('SELECT id FROM knowledge_categories WHERE slug = ?');
    $stmt->execute(['onboarding']);
    $row = $stmt->fetch();
    if ($row) {
        $catId = (int)$row['id'];
    } else {
        $maxSort = (int)($pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM knowledge_categories')->fetchColumn() ?? 0);
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO knowledge_categories (name, slug, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute(['Onboarding', 'onboarding', $maxSort + 10, $now, $now]);
        $catId = (int)$pdo->lastInsertId();
        fwrite(STDOUT, "  + created category «Onboarding»\n");
    }

    $seedsDir = dirname(__DIR__) . '/system/Database/seeds';
    $seeds = [
        ['locale' => 'en', 'title' => 'How to use Otack Manager',  'slug' => 'how-to-use-otack-manager'],
        ['locale' => 'uk', 'title' => 'Як користуватися Otack Manager', 'slug' => 'yak-koristuvatisya-otack-manager'],
        ['locale' => 'pl', 'title' => 'Jak używać Otack Manager',  'slug' => 'jak-uzywac-otack-manager'],
    ];
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    $find = $pdo->prepare('SELECT id FROM knowledge_pages WHERE slug = ?');
    $ins  = $pdo->prepare(
        'INSERT INTO knowledge_pages (slug, title, body_md, category_id, author_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $upd  = $pdo->prepare(
        'UPDATE knowledge_pages SET title = ?, body_md = ?, category_id = ?, updated_at = ?
          WHERE slug = ?'
    );

    foreach ($seeds as $seed) {
        $bodyPath = $seedsDir . '/welcome_' . $seed['locale'] . '.md';
        if (!is_file($bodyPath)) {
            fwrite(STDERR, "  ! missing source: $bodyPath\n");
            continue;
        }
        $body = (string)file_get_contents($bodyPath);

        $find->execute([$seed['slug']]);
        $existingId = $find->fetchColumn();

        if ($existingId === false) {
            $ins->execute([$seed['slug'], $seed['title'], $body, $catId, $authorId, $now, $now]);
            fwrite(STDOUT, "  + inserted {$seed['locale']}: {$seed['title']}\n");
        } elseif ($force) {
            $upd->execute([$seed['title'], $body, $catId, $now, $seed['slug']]);
            fwrite(STDOUT, "  ~ updated  {$seed['locale']}: {$seed['title']}\n");
        } else {
            fwrite(STDOUT, "  · exists   {$seed['locale']}: {$seed['title']} (pass --force to overwrite)\n");
        }
    }

    fwrite(STDOUT, "Done.\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
