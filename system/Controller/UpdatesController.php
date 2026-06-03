<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Service\Updater;
use App\View\Renderer;

/**
 * Updates endpoints.
 *
 *   GET  /api/updates/check  — manual "Check now" trigger (admin only)
 *   POST /admin/updates/run  — run the update pipeline (admin only)
 *
 * Both refuse to run when UPDATE_ENABLED=false.
 */
final class UpdatesController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private Updater $updater,
    ) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
        if (!Updater::isEnabled()) {
            Response::forbidden('Updates are disabled on this install (UPDATE_ENABLED=false)');
            exit;
        }
    }

    /**
     * GET /api/updates/check — forces a fresh GitHub lookup, refreshes
     * the cache, returns JSON. Errors (rate-limit, unreachable, wrong
     * repo URL) surface as 502 with the message so the admin sees them.
     */
    public function check(Request $req, array $params = []): void
    {
        try {
            $payload = $this->updater->check();
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 502);
            return;
        }
        Response::json($payload);
    }

    /**
     * POST /admin/updates/run — runs the full update pipeline
     * synchronously. The request can take 15-60s. On success we redirect
     * back to the Updates tab with a flash. On failure we redirect with
     * an error flash (the pipeline has already rolled itself back).
     *
     * The target version comes from the live cached payload so the admin
     * can't be raced into installing a downgrade or a tag they didn't see.
     */
    public function run(Request $req, array $params = []): void
    {
        // Re-check live so we're acting on the freshest available tag,
        // not whatever the dashboard cached an hour ago.
        try {
            $payload = $this->updater->check();
        } catch (\Throwable $e) {
            Response::redirect('/admin/settings?tab=updates&update_error=' . urlencode($e->getMessage()));
            return;
        }

        if (empty($payload['has_update']) || empty($payload['available'])) {
            Response::redirect('/admin/settings?tab=updates&update_error=up_to_date');
            return;
        }

        // Long pipelines need long script time.
        @set_time_limit(300);
        @ignore_user_abort(true);

        try {
            $result = $this->updater->update((string)$payload['available'], (int)($this->user['id'] ?? 0) ?: null);
            Response::redirect(
                '/admin/settings?tab=updates'
                . '&updated_to=' . urlencode($result['to'])
                . '&duration=' . (int)$result['duration_seconds']
            );
        } catch (\Throwable $e) {
            error_log('[updater] ' . $e->getMessage());
            Response::redirect('/admin/settings?tab=updates&update_error=' . urlencode($e->getMessage()));
        }
    }

    /**
     * POST /admin/updates/restore/{id} — runs the restore pipeline
     * synchronously. Like run(), it can take 15-60s. On success the
     * admin lands back on the Updates tab with a restore flash.
     */
    public function restore(\App\Http\Request $req, array $params = []): void
    {
        $backupId = (int)($params['id'] ?? 0);
        if ($backupId <= 0) {
            Response::redirect('/admin/settings?tab=updates&restore_error=' . urlencode('Missing backup id'));
            return;
        }

        @set_time_limit(300);
        @ignore_user_abort(true);

        try {
            $result = $this->updater->restore($backupId, (int)($this->user['id'] ?? 0) ?: null);
            Response::redirect(
                '/admin/settings?tab=updates'
                . '&restored_to=' . urlencode($result['to'])
                . '&duration=' . (int)$result['duration_seconds']
            );
        } catch (\Throwable $e) {
            error_log('[updater:restore] ' . $e->getMessage());
            Response::redirect('/admin/settings?tab=updates&restore_error=' . urlencode($e->getMessage()));
        }
    }
}
