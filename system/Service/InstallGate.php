<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\SettingsRepository;
use App\Repository\UserRepository;

/**
 * Single-predicate gate: should the install wizard fire?
 *
 * Both conditions must be true for the wizard to fire:
 *  - settings.installed_at is empty
 *  - users table has 0 approved admins
 *
 * Strict because either signal alone is recoverable to "fresh state":
 *  - Admin deleted by hand → installed_at still set → no wizard
 *    re-run (operator must clear installed_at manually if they really
 *    want the anon-accessible wizard back).
 *  - Tarball cloned into a fresh DB → no admin yet, no installed_at
 *    yet → wizard fires.
 */
final class InstallGate
{
    public static function isInstallRequired(\PDO $pdo): bool
    {
        $settings = new SettingsRepository($pdo);
        if ($settings->get('installed_at', '') !== '') return false;
        $users = new UserRepository($pdo);
        return $users->countApprovedAdmins() === 0;
    }
}
