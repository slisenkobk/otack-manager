<?php
declare(strict_types=1);

// v1.6.7: force a CSS/JS cache-bust on deploy.
//
// asset_url() appends `?v={settings.asset_version}` so browsers refetch
// styles/scripts only when that value changes. Until now it was bumped
// manually from Compass, which meant a release that ships CSS/JS fixes
// (e.g. the forms-data status dropdown clipping fix) could be served stale
// from the browser cache after a self-update. Stamping a fresh value here
// guarantees this release's assets are refetched without any manual step.
//
// From this version on the self-updater also bumps asset_version after a
// successful update, so future releases cache-bust automatically — this
// one-off migration covers the upgrade *to* the version that adds that.
//
// Idempotent: upserts a fixed value. Re-running sets the same string.
// Driver-portable: SELECT-then-UPDATE/INSERT instead of dialect-specific
// UPSERT syntax.
return function (\PDO $pdo): void {
    $value = '1.6.7';

    $existing = $pdo->prepare("SELECT value FROM settings WHERE key='asset_version'");
    $existing->execute();
    $row = $existing->fetch();

    if ($row === false) {
        $ins = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('asset_version', :v)");
        $ins->execute([':v' => $value]);
    } else {
        $upd = $pdo->prepare("UPDATE settings SET value = :v WHERE key='asset_version'");
        $upd->execute([':v' => $value]);
    }
};
