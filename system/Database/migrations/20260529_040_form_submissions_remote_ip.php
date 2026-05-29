<?php
declare(strict_types=1);

// For installations created before form_submissions had remote_ip.
return function (\PDO $pdo) {
    $cols = $pdo->query('PRAGMA table_info(form_submissions)')->fetchAll(\PDO::FETCH_ASSOC);
    if (!$cols) return;
    foreach ($cols as $c) { if ($c['name'] === 'remote_ip') return; }
    $pdo->exec('ALTER TABLE form_submissions ADD COLUMN remote_ip TEXT NULL');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_form_submissions_ip_time ON form_submissions(remote_ip, created_at)');
};
