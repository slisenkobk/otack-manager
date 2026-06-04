<?php
declare(strict_types=1);
namespace App\Controller;

use App\Database\Connection;
use App\Http\AuthGuard;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Service\CompassService;
use App\Service\ConfigStore;
use App\Service\DbMigrator;
use App\Service\EventBus;
use App\Service\Log;
use App\View\Renderer;
use PDO;

// Admin-only diagnostic / housekeeping panel. Four tabs: migrations,
// cache (sessions + orphan uploads + asset bust), DB stats, logs.
// Destructive actions log to activity_log and fire `compass.action`
// so admins see them in the Telegram channel.
final class CompassController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private CompassService $compass,
        private DbMigrator $dbMigrator,
        private PDO $db,
        private ActivityLogRepository $activity,
        private EventBus $events,
    ) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
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
        $keepDays = max(1, (int)\App\App::env('ACTIVITY_LOG_KEEP_DAYS', '180'));
        $cutoff = (new \DateTimeImmutable())->modify("-{$keepDays} days")->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM activity_log WHERE created_at < ?');
        $stmt->execute([$cutoff]);
        $activityStale = (int)$stmt->fetchColumn();
        $activityTotal = (int)$this->db->query('SELECT COUNT(*) FROM activity_log')->fetchColumn();

        $this->renderTab('cache', t('compass.tab.cache'), [
            'sessions'      => $this->compass->sessionsStats(),
            'uploads'       => $this->compass->uploadsStats(),
            'assetVersion'  => $this->compass->currentAssetVersion(),
            'activity'      => [
                'total'      => $activityTotal,
                'stale'      => $activityStale,
                'keep_days'  => $keepDays,
            ],
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
        $currentDriver = Connection::driverFor($this->db)?->name() ?? 'sqlite';
        $plan = null;
        if ($currentDriver === 'sqlite') {
            try {
                $plan = $this->dbMigrator->plan($this->db);
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
        $res = $this->dbMigrator->testConnection($config);
        Response::json($res);
    }

    /**
     * POST /admin/compass/db-migrate/start — kick off the migration.
     * Synchronous: the request returns when the copy finishes.
     */
    public function dbMigrateStart(Request $req, array $params = []): void
    {
        $currentDriver = Connection::driverFor($this->db)?->name();
        if ($currentDriver !== 'sqlite') {
            Response::json(['ok' => false, 'error' => 'Migration only runs from a SQLite source'], 400);
            return;
        }
        $config = $this->collectMysqlConfig($req);
        try {
            $report = $this->dbMigrator->migrate($this->db, $config);
        } catch (\Throwable $e) {
            Log::error('db_migrator', $e->getMessage());
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
        Response::json($this->dbMigrator->verifyEnv());
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

    // ─── Platform Settings tab (TODO #10, Task 5) ────────────────────────────

    /**
     * GET /admin/compass/platform — render the post-install settings editor.
     * Shares the ConfigStore overlay (data/config.json) with the install
     * wizard so admins can edit the same surface (LOGIN_HASH, APP_SECRET,
     * APP_URL, TRUSTED_PROXIES, Telegram, updates) without re-running the
     * wizard. Secrets are masked on the way out — the raw token/hash is
     * never echoed back to the form.
     */
    public function platform(Request $req, array $params = []): void
    {
        $config = new ConfigStore();
        $values = $config->load();
        $tgToken = $values['TG_BOT_TOKEN'] ?? '';
        $tgMask  = $tgToken !== ''
            ? 'bot****' . substr($tgToken, -4)
            : '';
        // LOGIN_HASH is 16 hex chars (8 random bytes). Exposing the last 4
        // would cut the effective key space of the /login?hash= URL gate
        // from 16^16 to 16^12 — meaningful against a brute-forcer. Render
        // a fixed placeholder; the value is shown ONLY on first
        // generation (one-shot toast) and never again.
        $loginHash = $values['LOGIN_HASH'] ?? '';
        $loginHashMask = $loginHash !== '' ? '••••••••••••••••' : '';
        $driver = Connection::driverFor($this->db)?->name() ?? 'sqlite';

        $this->renderTab('platform', t('compass.tab.platform'), [
            'config' => [
                'APP_URL'                => $values['APP_URL'] ?? '',
                'TRUSTED_PROXIES'        => $values['TRUSTED_PROXIES'] ?? '',
                'LOGIN_HASH_SET'         => $loginHash !== '',
                'LOGIN_HASH_MASK'        => $loginHashMask,
                'APP_SECRET_SET'         => isset($values['APP_SECRET']),
                'TG_BOT_TOKEN_MASK'      => $tgMask,
                'TG_CHAT_ID'             => $values['TG_CHAT_ID'] ?? '',
                'UPDATE_ENABLED'         => ($values['UPDATE_ENABLED'] ?? 'true') === 'true',
                'UPDATE_CHECK_INTERVAL'  => (int)($values['UPDATE_CHECK_INTERVAL'] ?? 3600),
                'UPDATE_BACKUP_KEEP'     => (int)($values['UPDATE_BACKUP_KEEP'] ?? 5),
            ],
            'driver'  => $driver,
            'saved'   => (string)($req->query['saved'] ?? ''),
            'flash'   => (string)($req->query['flash'] ?? ''),
        ]);
    }

    /**
     * POST /admin/compass/platform — process one section's form.
     * Section is dispatched on the hidden `section` field; each branch
     * writes through ConfigStore (which re-validates) then redirects back
     * with `?saved=<section>` so the GET handler can flash a confirmation.
     */
    public function updatePlatform(Request $req, array $params = []): void
    {
        $config = new ConfigStore();
        $section = (string)($req->post['section'] ?? '');
        try {
            switch ($section) {
                case 'auth':
                    if (!empty($req->post['enable_login_hash'])) {
                        $config->set(['LOGIN_HASH' => bin2hex(random_bytes(8))]);
                    } else {
                        $config->unset(['LOGIN_HASH']);
                    }
                    break;
                case 'rotate-app-secret':
                    $config->set(['APP_SECRET' => bin2hex(random_bytes(32))]);
                    break;
                case 'urls':
                    $writes = ['APP_URL' => trim((string)($req->post['app_url'] ?? ''))];
                    $tp = trim((string)($req->post['trusted_proxies'] ?? ''));
                    if ($tp !== '') {
                        $writes['TRUSTED_PROXIES'] = $tp;
                    } else {
                        $config->unset(['TRUSTED_PROXIES']);
                    }
                    $config->set($writes);
                    break;
                case 'telegram':
                    $writes = [];
                    $token = trim((string)($req->post['tg_bot_token'] ?? ''));
                    $chat  = trim((string)($req->post['tg_chat_id']   ?? ''));
                    // Skip writes when the field still holds the masked value
                    // we rendered (bot****XXXX) — that means the admin didn't
                    // touch it and we'd otherwise overwrite the real token with
                    // garbage.
                    if ($token !== '' && !str_starts_with($token, 'bot****')) {
                        $writes['TG_BOT_TOKEN'] = $token;
                    }
                    if ($chat !== '') {
                        $writes['TG_CHAT_ID'] = $chat;
                    }
                    if (!empty($writes)) {
                        $config->set($writes);
                    }
                    break;
                case 'updates':
                    $config->set([
                        'UPDATE_ENABLED'        => !empty($req->post['update_enabled']) ? 'true' : 'false',
                        'UPDATE_CHECK_INTERVAL' => (int)($req->post['update_check_interval'] ?? 3600),
                        'UPDATE_BACKUP_KEEP'    => (int)($req->post['update_backup_keep']    ?? 5),
                    ]);
                    break;
                default:
                    Response::redirect('/admin/compass/platform?flash=' . rawurlencode('Unknown section: ' . $section));
                    return;
            }
        } catch (\InvalidArgumentException $e) {
            Response::redirect('/admin/compass/platform?flash=' . rawurlencode($e->getMessage()));
            return;
        }

        $summary = t('platform.audit.section_saved', ['section' => $section]);
        $this->auditAndNotify(
            'compass.platform.update',
            $summary,
            ['section' => $section],
            $summary,
        );
        Response::redirect('/admin/compass/platform?saved=' . rawurlencode($section));
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

    public function pruneActivityLog(Request $req, array $params = []): void
    {
        $days = max(1, (int)\App\App::env('ACTIVITY_LOG_KEEP_DAYS', '180'));
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $count = $this->activity->pruneBefore($cutoff);
        $summary = t('compass.activity_log.pruned', ['count' => $count, 'days' => $days]);
        $this->auditAndNotify(
            'compass.activity_log.pruned',
            $summary,
            ['deleted' => $count, 'cutoff' => $cutoff, 'keep_days' => $days],
            $summary,
        );
        Response::json(['ok' => true, 'message' => $summary, 'deleted' => $count]);
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
        $this->activity->log($event, $userId, null, null, $summary, $meta);
        $this->events->fire('compass.action', [
            'actor_id'    => $userId,
            'actor_name'  => $userName,
            'event'       => $event,
            'summary'     => $tgVerbSummary,
            'meta'        => $meta,
        ]);
    }

}
