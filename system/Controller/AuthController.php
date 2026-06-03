<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Http\Csrf;
use App\Auth\AuthManager;
use App\Repository\UserRepository;
use App\View\Renderer;

final class AuthController extends BaseController {
    private AuthManager $auth;
    private UserRepository $users;
    private Csrf $csrf;

    public function __construct(Renderer $view, ?array $user = null) {
        parent::__construct($view, $user);
        $this->auth  = App::make('auth');
        $this->users = App::make('users');
        $this->csrf  = App::make('csrf');
    }

    private function &session(): array {
        $obj = App::make('session');
        return $obj->store;
    }

    private function flash(string $key, string $value): void {
        $s = &$this->session();
        $s[$key] = $value;
    }

    private function consumeFlash(string $key): ?string {
        $s = &$this->session();
        $v = $s[$key] ?? null;
        unset($s[$key]);
        return $v;
    }

    public function loginForm(Request $req, array $params = []): void {
        if ($this->user) { Response::redirect('/'); return; }
        Response::html($this->view->render('auth/login', [
            'title'     => t('auth.sign_in'),
            'csrfToken' => $this->csrf->token(),
            'error'     => $this->consumeFlash('flash_error'),
        ], 'layouts/auth'));
    }

    public function login(Request $req, array $params = []): void {
        $email    = trim($req->post['email'] ?? '');
        $password = $req->post['password'] ?? '';
        $remember = !empty($req->post['remember']);
        $result   = $this->auth->login($email, $password);

        if ($result === null) {
            $this->flash('flash_error', t('auth.invalid_credentials'));
            Response::redirect('/login'); return;
        }
        if ($result === 'throttled') {
            $this->flash('flash_error', t('auth.throttled'));
            Response::redirect('/login'); return;
        }
        if ($result === 'pending') {
            Response::redirect('/pending'); return;
        }
        if ($result === 'blocked') {
            $this->flash('flash_error', t('auth.account_blocked'));
            Response::redirect('/login'); return;
        }
        // Persist the remember-me intent so every subsequent request can
        // re-issue the session cookie with the right horizon (sliding window
        // in public/index.php).
        $session = App::make('session');
        $session->store['__remember'] = $remember;
        App::make('session_manager')->extendCookie(
            $remember
                ? \App\Auth\SessionManager::REMEMBER_LIFETIME
                : \App\Auth\SessionManager::DEFAULT_LIFETIME
        );
        // success — drop the resolved-locale cache so the post-login page picks
        // up the new user's locale instead of the Accept-Language fallback.
        i18n_reset_cache();
        // Defeat session fixation: rotate the session id after privilege change.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $this->csrf->regenerate();
        Response::redirect('/');
    }

    public function registerForm(Request $req, array $params = []): void {
        Response::html($this->view->render('auth/register', [
            'title'     => t('auth.create_account'),
            'csrfToken' => $this->csrf->token(),
            'error'     => $this->consumeFlash('flash_error'),
        ], 'layouts/auth'));
    }

    public function register(Request $req, array $params = []): void {
        $name     = trim($req->post['name'] ?? '');
        $email    = trim($req->post['email'] ?? '');
        $password = $req->post['password'] ?? '';

        if ($name === '') {
            $this->flash('flash_error', t('auth.name_required'));
            Response::redirect('/register'); return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('flash_error', t('auth.invalid_credentials'));
            Response::redirect('/register'); return;
        }
        if (strlen($password) < 8) {
            $this->flash('flash_error', t('auth.password_too_short'));
            Response::redirect('/register'); return;
        }
        if ($this->users->findByEmail($email)) {
            $this->flash('flash_error', t('auth.email_taken'));
            Response::redirect('/register'); return;
        }

        $hasher = App::make('hasher');
        $hash   = $hasher->hash($password);
        $id     = $this->users->create($email, $hash, $name);

        // Inherit the admin-configured default locale, but never write an
        // unknown code (defaults to 'en' if the setting is missing/invalid).
        $defaultLocale = App::make('settings')->get('default_locale', 'en');
        if (!in_array($defaultLocale, available_locales(), true)) $defaultLocale = 'en';
        $this->users->updateLocale($id, $defaultLocale);

        App::make('events')->fire('user.registered', [
            'user_id' => $id,
            'name'    => $name,
            'email'   => $email,
        ]);
        $user = $this->users->findById($id);

        // First-ever user is auto-approved + auto-logged-in
        if (($user['status'] ?? '') === 'approved') {
            $s = &$this->session();
            $s['user_id'] = (int)$id;
            i18n_reset_cache();
            // Defeat session fixation: rotate the session id after privilege change.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $this->csrf->regenerate();
            Response::redirect('/'); return;
        }

        Response::redirect('/pending');
    }

    public function pending(Request $req, array $params = []): void {
        Response::html($this->view->render('auth/pending', [
            'title'     => t('auth.pending_title'),
            'csrfToken' => $this->csrf->token(),
        ], 'layouts/auth'));
    }

    public function logout(Request $req, array $params = []): void {
        $this->auth->logout();
        $this->csrf->regenerate();
        Response::redirect('/login');
    }
}
