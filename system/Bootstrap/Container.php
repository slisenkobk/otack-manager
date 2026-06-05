<?php
declare(strict_types=1);

namespace App\Bootstrap;

use App\App;
use App\Auth\SessionManager;
use App\Database\{Connection, SchemaBootstrap};
use App\Http\Csrf;
use App\View\Renderer;

/**
 * Centralised DI container registration.
 *
 * All `App::singleton(...)` calls live here so public/index.php stays a thin
 * request-time glue layer. Call order: invoke once after App::reset() and
 * after the SessionManager / Csrf instances exist, since several factories
 * close over $sessionStore by reference and $csrf / $session by value.
 */
final class Container
{
    public static function register(array &$sessionStore, Csrf $csrf, SessionManager $session): void
    {
        self::registerCore();
        self::registerHttp($sessionStore, $csrf, $session);
    }

    /**
     * HTTP-free service registrations — safe to call from CLI scripts
     * (cron, deploy, bin/*.php). Skips `auth` / `csrf` / `session` /
     * `session_manager` since those need a live HTTP request to be
     * meaningful. Keeps the DB, settings, updater, repositories, and
     * other plumbing every offline runner needs.
     */
    public static function registerCore(): void
    {
        // Overlay config.json on top of .env so App::env() picks up wizard
        // values. ConfigStore re-validates against ALLOWED_KEYS on read, so
        // a tampered file cannot inject arbitrary env vars.
        $overlay = (new \App\Service\ConfigStore())->load();
        foreach ($overlay as $k => $v) {
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }

        App::singleton('db',     fn() => Connection::openFromEnv());
        App::singleton('driver', fn() => Connection::driverFor(App::make('db')));
        App::singleton('schema', fn() => new SchemaBootstrap(App::make('db')));
        App::singleton('view',   fn() => new Renderer(APP_ROOT . '/views'));

        App::singleton('users',   fn() => new \App\Repository\UserRepository(App::make('db')));
        App::singleton('projects', fn() => new \App\Repository\ProjectRepository(App::make('db')));
        App::singleton('members',  fn() => new \App\Repository\ProjectMemberRepository(App::make('db')));
        App::singleton('columns',  fn() => new \App\Repository\TaskColumnRepository(App::make('db')));
        App::singleton('tasks',    fn() => new \App\Repository\TaskRepository(App::make('db')));
        App::singleton('task_links', fn() => new \App\Repository\TaskLinkRepository(App::make('db')));
        App::singleton('settings',  fn() => new \App\Repository\SettingsRepository(App::make('db')));
        // Per-request memo of the asset cache-buster. Lives as a DI singleton
        // so App::reset() (called at the top of every request) refreshes it,
        // and a single page render does one SELECT instead of N (one per
        // <script>/<link> emitted by views).
        App::singleton('asset_version', function () {
            $v = '';
            try { $v = App::make('settings')->get('asset_version', ''); }
            catch (\Throwable $_) {}
            return new class($v) { public function __construct(public readonly string $value) {} };
        });
        App::singleton('forms',     fn() => new \App\Repository\FormRepository(App::make('db')));
        App::singleton('form_submissions', fn() => new \App\Repository\FormSubmissionRepository(App::make('db')));
        App::singleton('short_links',       fn() => new \App\Repository\ShortLinkRepository(App::make('db')));
        App::singleton('short_link_visits', fn() => new \App\Repository\ShortLinkVisitRepository(App::make('db')));
        App::singleton('polls',             fn() => new \App\Repository\PollRepository(App::make('db')));
        App::singleton('poll_votes',        fn() => new \App\Repository\PollVoteRepository(App::make('db')));
        App::singleton('app_versions',      fn() => new \App\Repository\AppVersionRepository(App::make('db')));
        App::singleton('app_backups',       fn() => new \App\Repository\AppBackupRepository(App::make('db')));
        App::singleton('updater',           fn() => new \App\Service\Updater(App::make('settings')));
        App::singleton('db_migrator',       fn() => new \App\Service\DbMigrator());
        App::singleton('config_store',      fn() => new \App\Service\ConfigStore());
        App::singleton('hasher',  fn() => new \App\Auth\PasswordHasher());
        App::singleton('events',   fn() => new \App\Service\EventBus());
        App::singleton('comments', fn() => new \App\Repository\CommentRepository(App::make('db')));
        App::singleton('notif_log', fn() => new \App\Repository\NotificationLogRepository(App::make('db')));
        App::singleton('activity', fn() => new \App\Repository\ActivityLogRepository(App::make('db')));
        App::singleton('api_tokens', fn() => new \App\Repository\ApiTokenRepository(App::make('db')));
        App::singleton('compass', fn() => new \App\Service\CompassService(
            App::make('db'),
            App::make('schema'),
            App::make('settings'),
            APP_ROOT . '/data/sessions',
            APP_ROOT . '/' . App::env('UPLOAD_DIR', 'public/uploads'),
            APP_ROOT . '/data/errors.log',
            \App\Database\Migrations::DIR,
        ));

        App::singleton('attachments', fn() => new \App\Repository\AttachmentRepository(App::make('db')));
        App::singleton('tags',     fn() => new \App\Repository\TagRepository(App::make('db')));
        App::singleton('uploader', fn() => new \App\Service\FileUploader(
            (int)App::env('UPLOAD_MAX_IMAGE', '5242880'),
            (int)App::env('UPLOAD_MAX_FILE', '52428800'),
            APP_ROOT . '/' . App::env('UPLOAD_DIR', 'public/uploads')
        ));
        App::singleton('login_throttle', fn() => new \App\Auth\LoginThrottle(App::make('db')));
    }

    /**
     * Register the HTTP-coupled services (auth / csrf / session). Split
     * out so CLI runners (Container::registerCore) can stay HTTP-free
     * without duplicating the rest of the wiring.
     */
    public static function registerHttp(array &$sessionStore, Csrf $csrf, SessionManager $session): void
    {
        App::singleton('auth',    function () use (&$sessionStore) {
            return new \App\Auth\AuthManager(
                App::make('users'),
                App::make('hasher'),
                $sessionStore,
                App::make('login_throttle'),
            );
        });
        App::singleton('csrf',    function () use ($csrf) { return $csrf; });
        App::singleton('session', function () use (&$sessionStore) {
            return new class($sessionStore) {
                public array $store;
                public function __construct(array &$s) { $this->store = &$s; }
            };
        });
        // Expose the SessionManager itself so the login flow can re-issue the
        // session cookie with the right lifetime (24h vs 30d for remember-me).
        App::singleton('session_manager', function () use ($session) { return $session; });
    }
}
