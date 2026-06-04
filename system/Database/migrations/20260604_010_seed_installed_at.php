<?php
declare(strict_types=1);

// Wave v1.5.0 / TODO #10: gate-safe upgrade for pre-1.5.0 installs.
//
// The new InstallGate (system/Service/InstallGate.php) fires the wizard
// when both: settings.installed_at is empty AND no approved admin exists.
// Operators upgrading from v1.4.x already have an admin row, so the gate
// stays closed for them once installed_at is stamped. We do that here so
// the v1.5.0 feature-flag flip is safe even on freshly-cloned dev environments
// where the wizard would otherwise fire on the next boot.
//
// Brand-new installs reach this migration BEFORE any admin exists, so the
// guard skips and the wizard fires normally — installed_at is stamped by
// the wizard itself in InstallController::integrationsSubmit.
return function (\PDO $pdo): void {
    $countAdmins = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='admin' AND status='approved'"
    )->fetchColumn();
    if ($countAdmins === 0) return;

    $existing = $pdo->prepare("SELECT value FROM settings WHERE key='installed_at'");
    $existing->execute();
    if ((string)$existing->fetchColumn() !== '') return;

    $ins = $pdo->prepare("INSERT INTO settings (key, value) VALUES ('installed_at', :v)");
    $ins->execute([':v' => gmdate('Y-m-d\TH:i:s\Z')]);
};
