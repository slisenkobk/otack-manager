<?php
declare(strict_types=1);

// Adds per-user locale preference (en / pl). Default 'en' so existing rows
// match the new default behaviour. The Profile page exposes a picker that
// writes to this column; new registrations inherit settings.default_locale
// (also defaults to 'en') if set, otherwise 'en'.
return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($cols as $c) { if ($c['name'] === 'locale') return; }
    $pdo->exec("ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
};
