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

        $this->renderTab('migrations', t('compass.tab.migrations'), [
            'status' => $status,
            'appliedAt' => $appliedAt,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function cache(Request $req, array $params = []): void
    {
        $this->renderTab('cache', t('compass.tab.cache'), [
            'sessions'      => $this->compass->sessionsStats(),
            'uploads'       => $this->compass->uploadsStats(),
            'assetVersion'  => $this->compass->currentAssetVersion(),
        ]);
    }

    public function stats(Request $req, array $params = []): void
    {
        $this->renderTab('stats', t('compass.tab.stats'), [
            'db' => $this->compass->dbStats(),
        ]);
    }

    public function logs(Request $req, array $params = []): void
    {
        $level = (string)($req->query['level'] ?? '');
        $this->renderTab('logs', t('compass.tab.logs'), [
            'entries'  => $this->compass->tailErrorsLog(50, $level !== '' ? $level : null),
            'logSize'  => $this->compass->errorsLogSize(),
            'level'    => $level,
        ]);
    }

    /**
     * Migrate to MySQL wizard. The current driver determines what the
     * page shows: on SQLite we render the form; on MySQL we render a
     * "you're already on MySQL" notice.
     */
    public function dbMigrate(Request $req, array $params = []): void
    {
        $currentDriver = \App\Database\Connection::driverFor(App::make('db'))?->name() ?? 'sqlite';
        $plan = null;
        if ($currentDriver === 'sqlite') {
            try {
                $plan = App::make('db_migrator')->plan(App::make('db'));
            } catch (\Throwable $_) { /* surface as null */ }
        }
        $this->renderTab('db-migrate', t('compass.tab.db_migrate'), [
            'currentDriver' => $currentDriver,
            'plan'          => $plan,
        ]);
    }

    /**
     * POST /admin/compass/db-migrate/test — opens a transient PDO to the
     * configured MySQL target, runs SELECT VERSION() + emptiness check.
     */
    public function dbMigrateTest(Request $req, array $params = []): void
    {
        $config = $this->collectMysqlConfig($req);
        $res = App::make('db_migrator')->testConnection($config);
        Response::json($res);
    }

    /**
     * POST /admin/compass/db-migrate/start — kick off the migration.
     * Synchronous: the request returns when the copy finishes.
     */
    public function dbMigrateStart(Request $req, array $params = []): void
    {
        $currentDriver = \App\Database\Connection::driverFor(App::make('db'))?->name();
        if ($currentDriver !== 'sqlite') {
            Response::json(['ok' => false, 'error' => 'Migration only runs from a SQLite source'], 400);
            return;
        }
        $config = $this->collectMysqlConfig($req);
        try {
            $report = App::make('db_migrator')->migrate(App::make('db'), $config);
        } catch (\Throwable $e) {
            error_log('[db_migrator] ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
            return;
        }
        $envLines = sprintf(
            "DB_DSN=mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4\nDB_USER=%s\nDB_PASSWORD=%s",
            $config['host'], $config['port'], $config['db'],
            $config['user'], $config['password']
        );
        $this->auditAndNotify(
            'compass.db_migrate.run',
            'Migrated ' . count($report['tables']) . ' tables to MySQL',
            $report,
            'SQLite → MySQL migration complete'
        );
        Response::json(['ok' => true, 'report' => $report, 'env' => $envLines]);
    }

    /**
     * GET /admin/compass/db-migrate/verify — called after the operator
     * edits .env. Reopens the connection from env and reports whether
     * the new MySQL is reachable + populated.
     */
    public function dbMigrateVerify(Request $req, array $params = []): void
    {
        Response::json(App::make('db_migrator')->verifyEnv());
    }

    /** @return array{host:string,port:int,db:string,user:string,password:string} */
    private function collectMysqlConfig(Request $req): array
    {
        return [
            'host'     => trim((string)($req->post['host']     ?? '127.0.0.1')),
            'port'     => (int)($req->post['port']             ?? 3306),
            'db'       => trim((string)($req->post['db']       ?? '')),
            'user'     => trim((string)($req->post['user']     ?? '')),
            'password' => (string)($req->post['password']      ?? ''),
        ];
    }

    // ─── POST actions ────────────────────────────────────────────────────────

    public function runMigrations(Request $req, array $params = []): void
    {
        $applied = $this->compass->runPendingMigrations();
        $count = count($applied);
        $summary = $count === 0
            ? t('compass.migrations.no_pending')
            : tn('compass.migrations.applied_n', $count, ['n' => $count]);
        $this->auditAndNotify(
            'compass.migrations.run',
            $summary,
            ['applied' => $applied],
            $count === 0
                ? t('compass.migrations.no_pending')
                : tn('compass.migrations.applied_n', $count, ['n' => $count])
        );
        Response::json(['ok' => true, 'applied' => $applied, 'message' => $summary]);
    }

    public function clearSessions(Request $req, array $params = []): void
    {
        $res = $this->compass->clearStaleSessions();
        $summary = tn('compass.cache.sessions.cleared', $res['deleted'], ['n' => $res['deleted']])
            . ' (' . human_bytes($res['bytes']) . ')';
        $this->auditAndNotify(
            'compass.cache.sessions_cleared',
            $summary,
            $res,
            tn('compass.cache.sessions.cleared', $res['deleted'], ['n' => $res['deleted']])
        );
        Response::json(['ok' => true, 'message' => $summary] + $res);
    }

    public function clearOrphanUploads(Request $req, array $params = []): void
    {
        $res = $this->compass->clearOrphanUploads();
        $summary = tn('compass.cache.uploads.cleared', $res['deleted'], ['n' => $res['deleted']])
            . ' (' . human_bytes($res['bytes']) . ')';
        $this->auditAndNotify(
            'compass.cache.uploads_orphans_cleared',
            $summary,
            $res,
            tn('compass.cache.uploads.cleared', $res['deleted'], ['n' => $res['deleted']])
        );
        Response::json(['ok' => true, 'message' => $summary] + $res);
    }

    public function clearErrorLog(Request $req, array $params = []): void
    {
        $res = $this->compass->clearErrorsLog();
        $summary = t('compass.logs.cleared', ['size' => human_bytes((int)$res['bytes'])]);
        $this->auditAndNotify(
            'compass.logs.cleared',
            $summary,
            $res,
            $summary
        );
        Response::json(['ok' => true, 'message' => $summary] + $res);
    }

    public function bustAssetCache(Request $req, array $params = []): void
    {
        $version = $this->compass->bumpAssetVersion();
        $summary = t('compass.cache.assets.bumped', ['version' => $version]);
        $this->auditAndNotify(
            'compass.cache.asset_bust',
            $summary,
            ['version' => $version],
            $summary
        );
        Response::json(['ok' => true, 'version' => $version, 'message' => $summary]);
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
            'user' => $this->user, 'crumb' => t('nav.compass') . ' · ' . $crumb,
        ]);
        $content = $this->view->render(
            'admin/compass/' . $tab,
            $extra + ['currentTab' => $tab, 'csrfToken' => $csrfToken],
        );
        Response::html($this->view->render('layouts/main', [
            'title'     => t('nav.compass') . ' · ' . $crumb,
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
