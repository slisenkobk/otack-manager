<?php
declare(strict_types=1);

// Seed the «Welcome / How to use Otack Manager» knowledge page in all three
// supported system languages (en / uk / pl). Idempotent:
//
//   • Skips entirely if any knowledge_pages row already exists — we never
//     overwrite operator content.
//   • Skips entirely if no approved admin exists yet (fresh install, before
//     the wizard ran). The CLI bin/seed-welcome-doc.php exists for re-seeding
//     after the wizard has run, and it shares the same guard.
//
// The 3 seed bodies live next to this file as plain .md files so they can be
// edited without touching PHP. They are read at migration time and inserted
// verbatim into knowledge_pages.body_md.
return function (\PDO $pdo): void {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM knowledge_pages')->fetchColumn();
    if ($count > 0) return;

    $admin = $pdo->query(
        "SELECT id FROM users WHERE role='admin' AND status='approved' ORDER BY id ASC LIMIT 1"
    )->fetchColumn();
    if (!$admin) return;
    $authorId = (int)$admin;

    $seedsDir = __DIR__ . '/../seeds';
    $seeds = [
        ['locale' => 'en', 'title' => 'How to use Otack Manager',  'slug' => 'how-to-use-otack-manager',   'category' => 'Onboarding'],
        ['locale' => 'uk', 'title' => 'Як користуватися Otack Manager', 'slug' => 'yak-koristuvatisya-otack-manager', 'category' => 'Onboarding'],
        ['locale' => 'pl', 'title' => 'Jak używać Otack Manager',  'slug' => 'jak-uzywac-otack-manager',    'category' => 'Onboarding'],
    ];

    // Ensure the "Onboarding" category exists. Reuse if already present so we
    // never duplicate. listAll() would need the repo; raw SQL keeps the
    // migration self-contained and driver-portable.
    $catId = null;
    $stmt = $pdo->prepare('SELECT id FROM knowledge_categories WHERE slug = ?');
    $stmt->execute(['onboarding']);
    $row = $stmt->fetch();
    if ($row) {
        $catId = (int)$row['id'];
    } else {
        $maxSort = (int)($pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM knowledge_categories')->fetchColumn() ?? 0);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO knowledge_categories (name, slug, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute(['Onboarding', 'onboarding', $maxSort + 10, $now, $now]);
        $catId = (int)$pdo->lastInsertId();
    }

    $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $insertPage = $pdo->prepare(
        'INSERT INTO knowledge_pages (slug, title, body_md, category_id, author_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($seeds as $seed) {
        $bodyPath = $seedsDir . '/welcome_' . $seed['locale'] . '.md';
        if (!is_file($bodyPath)) continue;
        $body = (string)file_get_contents($bodyPath);
        $insertPage->execute([
            $seed['slug'],
            $seed['title'],
            $body,
            $catId,
            $authorId,
            $now,
            $now,
        ]);
    }
};
