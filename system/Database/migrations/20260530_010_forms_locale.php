<?php
declare(strict_types=1);

// Per-form locale — controls the language the public form is rendered in.
// Defaults to 'en' so any pre-existing form keeps its current behaviour
// (the public-form view previously fell back to Accept-Language / 'en').
return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(forms)')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($cols as $c) { if ($c['name'] === 'locale') return; }
    $pdo->exec("ALTER TABLE forms ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'");
};
