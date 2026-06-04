<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Service\ConfigStore;
use App\Service\DbMigrator;
use App\View\Renderer;

/**
 * First-run install wizard (TODO #10).
 *
 * Six steps: welcome → db → admin → security → integrations → done.
 * Each POST writes immediately to the ConfigStore overlay (data/config.json)
 * and/or the DB. After the integrations step the controller stamps
 * settings.installed_at — that flip is what the gate at public/index.php +
 * the per-action `guardGet` check use to refuse subsequent /install/*
 * requests with a 404.
 *
 * Resume semantics: re-entering the wizard mid-walk is supported. The
 * controller derives "what's the earliest unfinished step?" from the DB
 * (admin row present?) and the ConfigStore (APP_SECRET written?) and
 * redirects you there from any later step.
 *
 * CSRF: every POST is verified by the central middleware in public/index.php
 * before this controller runs — no per-method check needed here. (The
 * routes were added to the central CSRF path; the install paths are NOT in
 * the form/poll bypass lists.)
 */
final class InstallController extends BaseController
{
    private ConfigStore $config;
    private UserRepository $users;
    private SettingsRepository $settings;
    private Csrf $csrf;
    private \PDO $pdo;
    private object $session;

    public function __construct(
        Renderer $view,
        ?array $user,
        \PDO $pdo,
        Csrf $csrf,
        ConfigStore $config,
        UserRepository $users,
        SettingsRepository $settings,
        object $session,
    ) {
        parent::__construct($view, $user);
        $this->pdo      = $pdo;
        $this->csrf     = $csrf;
        $this->config   = $config;
        $this->users    = $users;
        $this->settings = $settings;
        $this->session  = $session;
    }

    // ─── guards ──────────────────────────────────────────────────────────────

    /**
     * Returns true if /install/* should respond 404 — the wizard already
     * finished (settings.installed_at is set). Mid-wizard requests where
     * an admin has been created but installed_at is not yet stamped are
     * NOT blocked — the user is still walking through later steps.
     */
    private function gateBlocked(): bool
    {
        return $this->settings->get('installed_at', '') !== '';
    }

    /**
     * Stricter precondition for the FIRST mutating steps (db, admin):
     * also refuses when an approved admin already exists, regardless of
     * installed_at. Prevents a second-admin creation in the mid-wizard
     * window (admin exists, installed_at not stamped) — without this,
     * an attacker could POST /install/admin during that window and add
     * an anonymous super-user. The later steps (security, integrations)
     * MUST stay open in mid-wizard because they are the legitimate
     * continuation after the admin step.
     */
    private function adminAlreadyExists(): bool
    {
        return $this->users->countApprovedAdmins() > 0;
    }

    private function deny404(): void
    {
        Response::notFound('Install wizard is not available');
    }

    /**
     * Resume helper: when re-entering a step out of order, push the user back
     * to the earliest unfinished prerequisite step. Returns the URL to redirect
     * to, or null when the requested step is reachable.
     */
    private function resumeTo(string $expected): ?string
    {
        $hasAdmin     = $this->users->countApprovedAdmins() > 0;
        $hasAppSecret = $this->config->get('APP_SECRET') !== null;
        $order = ['welcome' => 0, 'db' => 1, 'admin' => 2, 'security' => 3, 'integrations' => 4, 'done' => 5];
        $rank  = $order[$expected] ?? 99;

        if (!$hasAdmin && $rank > $order['admin']) return '/install/admin';
        if (!$hasAppSecret && $rank > $order['security']) return '/install/security';
        return null;
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function render(string $template, array $data, string $currentStep): string
    {
        $data['csrfToken']   = $this->csrf->token();
        $data['currentStep'] = $currentStep;
        $data['title']       = t('install.title');
        return $this->view->render($template, $data, 'layouts/install');
    }

    private function detectedAppUrl(): string
    {
        $proto = ($_SERVER['HTTPS'] ?? '') === 'on'
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
              ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $proto . '://' . $host;
    }

    // ─── actions ─────────────────────────────────────────────────────────────

    public function welcome(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        Response::html($this->render('install/welcome', [], 'welcome'));
    }

    public function start(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        Response::redirect('/install/db');
    }

    public function db(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        $resume = $this->resumeTo('db');
        if ($resume !== null) { Response::redirect($resume); return; }
        Response::html($this->render('install/db', [
            'flash' => $req->query['flash'] ?? null,
        ], 'db'));
    }

    public function dbSubmit(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        // Mid-wizard hardening: once an admin exists, the DB choice is locked
        // (re-running migrations against a different DSN with live admin data
        // would orphan rows). Operators wanting to switch later use the
        // /admin/compass/db-migrate tool.
        if ($this->adminAlreadyExists()) { $this->deny404(); return; }

        $driver = (string)($req->post['driver'] ?? 'sqlite');
        if ($driver === 'sqlite') {
            // Nothing to write — DB_PATH default already points the boot
            // at data/app.sqlite, which migrations already populated on
            // first request.
            Response::redirect('/install/admin');
            return;
        }

        // MySQL path: validate connection + persist DSN to the overlay.
        $cfg = [
            'host'     => trim((string)($req->post['host']     ?? '127.0.0.1')),
            'port'     => (int)($req->post['port']             ?? 3306),
            'db'       => trim((string)($req->post['dbname']   ?? '')),
            'user'     => trim((string)($req->post['user']     ?? '')),
            'password' => (string)($req->post['password']      ?? ''),
        ];
        if ($cfg['db'] === '' || $cfg['user'] === '') {
            Response::redirect('/install/db?flash=' . rawurlencode(t('install.error.invalid_input')));
            return;
        }
        $migrator = new DbMigrator();
        $test = $migrator->testConnection($cfg);
        if (!($test['ok'] ?? false)) {
            $msg = t('install.error.db_test', ['msg' => (string)($test['error'] ?? '')]);
            Response::redirect('/install/db?flash=' . rawurlencode($msg));
            return;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'], $cfg['db']
        );
        try {
            $this->config->set([
                'DB_DSN'      => $dsn,
                'DB_USER'     => $cfg['user'],
                'DB_PASSWORD' => $cfg['password'],
            ]);
        } catch (\Throwable $e) {
            $msg = t('install.error.config_write', ['msg' => $e->getMessage()]);
            Response::redirect('/install/db?flash=' . rawurlencode($msg));
            return;
        }

        // Re-bootstrap the connection so the rest of THIS request (and
        // every subsequent one) sees the new DSN. The App container caches
        // the PDO via its 'db' singleton; clearing both is required.
        Connection::reset();
        App::reset();
        // Re-overlay the freshly-written config.json onto $_ENV so
        // Connection::openFromEnv() picks up DB_DSN. The boot path already
        // did this once at the top of public/index.php, but the wizard ran
        // AFTER that — re-apply now.
        $overlay = $this->config->load();
        foreach ($overlay as $k => $v) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }

        try {
            // Re-register container so the 'db' factory rebuilds with the new env.
            // Then run migrations on the target DB.
            $newPdo = Connection::openFromEnv();
            Migrations::run(new SchemaBootstrap($newPdo));
        } catch (\Throwable $e) {
            // Undo the overlay write so the operator can drop the target DB and retry.
            $this->config->unset(['DB_DSN', 'DB_USER', 'DB_PASSWORD']);
            Connection::reset();
            App::reset();
            $msg = t('install.error.migration', ['msg' => $e->getMessage()]);
            Response::redirect('/install/db?flash=' . rawurlencode($msg));
            return;
        }

        Response::redirect('/install/admin');
    }

    public function admin(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        Response::html($this->render('install/admin', [
            'flash' => $req->query['flash'] ?? null,
            'form'  => [],
        ], 'admin'));
    }

    public function adminSubmit(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        // Critical: an approved admin already exists — refuse to create a
        // second one via the unauthenticated install endpoint. Without this
        // an attacker who hits the wizard window between admin-creation and
        // installed_at-stamping could add a parallel super-user.
        if ($this->adminAlreadyExists()) { $this->deny404(); return; }

        $name     = trim((string)($req->post['name']     ?? ''));
        $email    = trim((string)($req->post['email']    ?? ''));
        $password = (string)($req->post['password']      ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::html($this->render('install/admin', [
                'flash' => t('install.error.invalid_input'),
                'form'  => ['name' => $name, 'email' => $email],
            ], 'admin'));
            return;
        }
        if ($this->users->findByEmail($email) !== null) {
            Response::html($this->render('install/admin', [
                'flash' => t('install.error.email_taken'),
                'form'  => ['name' => $name, 'email' => $email],
            ], 'admin'));
            return;
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        try {
            $this->users->createAdmin($name, $email, $hash);
        } catch (\Throwable $e) {
            Response::html($this->render('install/admin', [
                'flash' => $e->getMessage(),
                'form'  => ['name' => $name, 'email' => $email],
            ], 'admin'));
            return;
        }
        Response::redirect('/install/security');
    }

    public function security(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        $resume = $this->resumeTo('security');
        if ($resume !== null) { Response::redirect($resume); return; }
        Response::html($this->render('install/security', [
            'flash'           => $req->query['flash'] ?? null,
            'detectedAppUrl'  => $this->detectedAppUrl(),
        ], 'security'));
    }

    public function securitySubmit(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }

        $url = trim((string)($req->post['app_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            Response::redirect('/install/security?flash=' . rawurlencode(t('install.error.invalid_input')));
            return;
        }
        $writes = [
            'APP_SECRET' => bin2hex(random_bytes(32)),
            'APP_URL'    => $url,
        ];
        if (!empty($req->post['enable_login_hash'])) {
            $writes['LOGIN_HASH'] = bin2hex(random_bytes(8));
        }
        try {
            $this->config->set($writes);
        } catch (\InvalidArgumentException $e) {
            $msg = t('install.error.config_write', ['msg' => $e->getMessage()]);
            Response::redirect('/install/security?flash=' . rawurlencode($msg));
            return;
        }
        Response::redirect('/install/integrations');
    }

    public function integrations(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }
        $resume = $this->resumeTo('integrations');
        if ($resume !== null) { Response::redirect($resume); return; }
        Response::html($this->render('install/integrations', [
            'flash' => $req->query['flash'] ?? null,
        ], 'integrations'));
    }

    public function integrationsSubmit(Request $req, array $params = []): void
    {
        if ($this->gateBlocked()) { $this->deny404(); return; }

        $action = (string)($req->post['action'] ?? 'skip');
        if ($action === 'save') {
            $token = trim((string)($req->post['tg_token']    ?? ''));
            $chat  = trim((string)($req->post['tg_chat_id']  ?? ''));
            if ($token !== '' && $chat !== '') {
                try {
                    $this->config->set([
                        'TG_BOT_TOKEN' => $token,
                        'TG_CHAT_ID'   => $chat,
                    ]);
                } catch (\InvalidArgumentException $e) {
                    $msg = t('install.error.config_write', ['msg' => $e->getMessage()]);
                    Response::redirect('/install/integrations?flash=' . rawurlencode($msg));
                    return;
                }
            }
        }
        // Stamp settings.installed_at — this is the gate's "we're done" signal.
        // From this point on, every /install/* request 404s (the gateBlocked()
        // check above will return true on the next hit because the predicate
        // returns false when installed_at is set).
        //
        // Build the done-page summary BEFORE the stamp so we can show it once
        // by storing the payload in a one-shot session bucket, then redirect.
        $admin     = $this->users->firstApprovedAdmin();
        $loginHash = $this->config->get('LOGIN_HASH');
        $signInUrl = '/login' . ($loginHash !== null ? ('?hash=' . rawurlencode($loginHash)) : '');
        $summary = [
            'db'           => $this->config->get('DB_DSN') !== null ? 'MySQL' : 'SQLite',
            'admin_email'  => $admin['email'] ?? '',
            'login_hash'   => $loginHash !== null,
            'telegram'     => $this->config->get('TG_BOT_TOKEN') !== null,
            'sign_in_url'  => $signInUrl,
        ];
        // Stash for the done-page one-shot render.
        $session = $this->session;
        $session->store['__install_done_summary'] = $summary;

        $this->settings->set('installed_at', gmdate('Y-m-d\TH:i:s\Z'));
        Response::redirect('/install/done');
    }

    public function done(Request $req, array $params = []): void
    {
        // Special case: the done page is the ONLY /install/* URL allowed to
        // render once installed_at IS set — it's the post-stamp summary. Read
        // the one-shot summary from the session; if it isn't there (someone
        // hit /install/done directly after install), fall back to a generated
        // summary from current state.
        $session = $this->session;
        $summary = $session->store['__install_done_summary'] ?? null;
        unset($session->store['__install_done_summary']);

        if ($summary === null) {
            // Direct hit on /install/done after the wizard finished — refuse.
            // Otherwise this URL stays addressable indefinitely.
            if ($this->gateBlocked()) { $this->deny404(); return; }
            $admin     = $this->users->firstApprovedAdmin();
            $loginHash = $this->config->get('LOGIN_HASH');
            $summary = [
                'db'           => $this->config->get('DB_DSN') !== null ? 'MySQL' : 'SQLite',
                'admin_email'  => $admin['email'] ?? '',
                'login_hash'   => $loginHash !== null,
                'telegram'     => $this->config->get('TG_BOT_TOKEN') !== null,
                'sign_in_url'  => '/login' . ($loginHash !== null ? ('?hash=' . rawurlencode($loginHash)) : ''),
            ];
        }
        Response::html($this->render('install/done', ['summary' => $summary], 'done'));
    }
}
