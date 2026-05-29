<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Service\CompassService;

// Admin-only diagnostic / housekeeping panel. Four tabs: migrations,
// cache (sessions + orphan uploads + asset bust), DB stats, logs.
// Destructive actions log to activity_log and fire `compass.action`
// so admins see them in the Telegram channel.
final class CompassController extends BaseController
{
    private CompassService $compass;

    public function __construct($view, $user = null)
    {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
        $this->compass = App::make('compass');
    }

    public function index(Request $req, array $params = []): void
    {
        $this->redirectTo('/admin/compass/migrations');
    }

    // ─── GET tabs ────────────────────────────────────────────────────────────

    public function migrations(Request $req, array $params = []): void
    {
        $status = $this->compass->migrationsStatus();
        $applied = $this->compass->appliedMigrationDetails();
        // Build name => applied_at lookup so the list view can show both
        // "applied" badge and the timestamp inline.
        $appliedAt = [];
        foreach ($applied as $r) $appliedAt[$r['name']] = $r['applied_at'];
        $pendingCount = 0;
        foreach ($status as $r) if (!$r['applied']) $pendingCount++;

        $this->renderTab('migrations', 'Migrations', [
            'status' => $status,
            'appliedAt' => $appliedAt,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function cache(Request $req, array $params = []): void
    {
        $this->renderTab('cache', 'Cache', [
            'sessions'      => $this->compass->sessionsStats(),
            'uploads'       => $this->compass->uploadsStats(),
            'assetVersion'  => $this->compass->currentAssetVersion(),
        ]);
    }

    public function stats(Request $req, array $params = []): void
    {
        $this->renderTab('stats', 'DB stats', [
            'db' => $this->compass->dbStats(),
        ]);
    }

    public function logs(Request $req, array $params = []): void
    {
        $level = (string)($req->query['level'] ?? '');
        $this->renderTab('logs', 'Logs', [
            'entries'  => $this->compass->tailErrorsLog(100, $level !== '' ? $level : null),
            'logSize'  => $this->compass->errorsLogSize(),
            'level'    => $level,
        ]);
    }

    // ─── POST actions ────────────────────────────────────────────────────────

    public function runMigrations(Request $req, array $params = []): void
    {
        $applied = $this->compass->runPendingMigrations();
        $count = count($applied);
        $summary = $count === 0
            ? 'No pending migrations'
            : sprintf('Applied %d migration%s', $count, $count === 1 ? '' : 's');
        $this->auditAndNotify(
            'compass.migrations.run',
            $summary,
            ['applied' => $applied],
            $count === 0 ? 'no pending migrations' : "applied $count migration(s)"
        );
        Response::json(['ok' => true, 'applied' => $applied]);
    }

    public function clearSessions(Request $req, array $params = []): void
    {
        $res = $this->compass->clearStaleSessions();
        $summary = sprintf(
            'Cleared %d stale session%s (%s)',
            $res['deleted'],
            $res['deleted'] === 1 ? '' : 's',
            human_bytes($res['bytes'])
        );
        $this->auditAndNotify(
            'compass.cache.sessions_cleared',
            $summary,
            $res,
            sprintf('cleared %d stale sessions', $res['deleted'])
        );
        Response::json(['ok' => true] + $res);
    }

    public function clearOrphanUploads(Request $req, array $params = []): void
    {
        $res = $this->compass->clearOrphanUploads();
        $summary = sprintf(
            'Cleared %d orphan upload%s (%s)',
            $res['deleted'],
            $res['deleted'] === 1 ? '' : 's',
            human_bytes($res['bytes'])
        );
        $this->auditAndNotify(
            'compass.cache.uploads_orphans_cleared',
            $summary,
            $res,
            sprintf('cleared %d orphan uploads', $res['deleted'])
        );
        Response::json(['ok' => true] + $res);
    }

    public function bustAssetCache(Request $req, array $params = []): void
    {
        $version = $this->compass->bumpAssetVersion();
        $summary = 'Bumped asset cache version to ' . $version;
        $this->auditAndNotify(
            'compass.cache.asset_bust',
            $summary,
            ['version' => $version],
            'bumped asset cache version'
        );
        Response::json(['ok' => true, 'version' => $version]);
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $extra
     */
    private function renderTab(string $tab, string $crumb, array $extra): void
    {
        $csrfToken = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'compass', 'csrfToken' => $csrfToken,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Compass · ' . $crumb,
        ]);
        $content = $this->view->render(
            'admin/compass/' . $tab,
            $extra + ['currentTab' => $tab, 'csrfToken' => $csrfToken],
        );
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Compass · ' . $crumb,
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $content,
        ]));
    }

    private function redirectTo(string $path): void
    {
        Response::redirect($path);
    }

    /**
     * Write to activity_log and fire `compass.action` (the latter feeds the
     * Telegram listener registered in index.php).
     *
     * @param array<string,mixed> $meta
     */
    private function auditAndNotify(string $event, string $summary, array $meta, string $tgVerbSummary): void
    {
        $userId = (int)($this->user['id'] ?? 0);
        $userName = (string)($this->user['name'] ?? 'someone');
        App::make('activity')->log($event, $userId, null, null, $summary, $meta);
        App::make('events')->fire('compass.action', [
            'actor_id'    => $userId,
            'actor_name'  => $userName,
            'event'       => $event,
            'summary'     => $tgVerbSummary,
            'meta'        => $meta,
        ]);
    }

}
