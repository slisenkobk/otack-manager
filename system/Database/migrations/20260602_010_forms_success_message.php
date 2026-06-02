<?php
declare(strict_types=1);

// Per-form override for the public thank-you message. When NULL the public
// submit flow falls back to the global t('public_form.thanks_body') string.
return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(forms)')->fetchAll(\PDO::FETCH_ASSOC);
    foreach ($cols as $c) { if ($c['name'] === 'success_message') return; }
    $pdo->exec('ALTER TABLE forms ADD COLUMN success_message TEXT NULL');
};
