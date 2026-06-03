<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\UserRepository;
use App\Auth\PasswordHasher;

final class ProfileController extends BaseController {
    private UserRepository $users;
    private PasswordHasher $hasher;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->users = App::make('users');
        $this->hasher = App::make('hasher');
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

    public function show(Request $req, array $params = []): void {
        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'profile', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('nav.profile'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => t('nav.profile'),
            'csrfToken' => $csrfToken,
            'sidebar' => $sidebar,
            'topbar' => $topbar,
            'content' => $this->view->render('profile/show', [
                'user' => $this->user,
                'csrfToken' => $csrfToken,
                'success' => $this->consumeFlash('flash_success'),
                'error' => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function update(Request $req, array $params = []): void {
        // Single endpoint that backs the unified Profile form: name + email +
        // locale + optional avatar upload + optional password change. Each
        // section is opt-in (locale always submitted; avatar/password only
        // if the user actually filled them) so we can short-circuit cleanly.
        $name  = trim((string)($req->post['name']  ?? ''));
        $email = strtolower(trim((string)($req->post['email'] ?? '')));
        if ($name === '') {
            $this->flash('flash_error', t('auth.name_required'));
            Response::redirect('/profile'); return;
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('flash_error', t('field.email'));
            Response::redirect('/profile'); return;
        }

        // Password change is all-or-nothing: empty current_password means
        // "don't touch the password". Filled current_password requires both
        // new + confirm to be valid before we update anything.
        $current = (string)($req->post['current_password'] ?? '');
        $new     = (string)($req->post['new_password']     ?? '');
        $confirm = (string)($req->post['confirm_password'] ?? '');
        $changePw = $current !== '' || $new !== '' || $confirm !== '';
        if ($changePw) {
            if (!$this->hasher->verify($current, $this->user['password_hash'])) {
                $this->flash('flash_error', t('profile.password_wrong'));
                Response::redirect('/profile'); return;
            }
            if (strlen($new) < 8) {
                $this->flash('flash_error', t('auth.password_too_short'));
                Response::redirect('/profile'); return;
            }
            if ($new !== $confirm) {
                $this->flash('flash_error', t('profile.password_mismatch'));
                Response::redirect('/profile'); return;
            }
        }

        if (strtolower((string)$this->user['email']) !== $email) {
            $taken = $this->users->findByEmail($email);
            if ($taken && (int)$taken['id'] !== (int)$this->user['id']) {
                $this->flash('flash_error', t('profile.email_taken'));
                Response::redirect('/profile'); return;
            }
            $this->users->updateEmail((int)$this->user['id'], $email);
        }
        if ($name !== (string)$this->user['name']) {
            $this->users->updateName((int)$this->user['id'], $name);
        }

        $locale = (string)($req->post['locale'] ?? '');
        if (in_array($locale, available_locales(), true) && $locale !== ($this->user['locale'] ?? 'en')) {
            $this->users->updateLocale((int)$this->user['id'], $locale);
            i18n_reset_cache();
        }

        $file = $req->files['avatar'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $uploader = App::make('uploader');
            $err = $uploader->validate($file);
            if ($err) { $this->flash('flash_error', $err); Response::redirect('/profile'); return; }
            if (!$uploader->isImage($file['type'] ?? '')) {
                $this->flash('flash_error', t('profile.avatar_hint'));
                Response::redirect('/profile'); return;
            }
            try {
                $stored = $uploader->store($file);
            } catch (\Throwable $e) {
                $this->flash('flash_error', $e->getMessage());
                Response::redirect('/profile'); return;
            }
            if (!empty($this->user['avatar'])) {
                $oldAbs = APP_ROOT . '/public/' . ltrim($this->user['avatar'], '/');
                if (is_file($oldAbs)) @unlink($oldAbs);
            }
            $this->users->updateAvatar((int)$this->user['id'], $stored['filename']);
        }

        if ($changePw) {
            $this->users->updatePassword((int)$this->user['id'], $this->hasher->hash($new));
        }

        $this->flash('flash_success', t('profile.saved'));
        Response::redirect('/profile');
    }

    public function updatePassword(Request $req, array $params = []): void {
        $current = $req->post['current'] ?? '';
        $new = $req->post['new'] ?? '';
        $confirm = $req->post['confirm'] ?? '';
        if (!$this->hasher->verify($current, $this->user['password_hash'])) {
            $this->flash('flash_error', t('profile.password_wrong'));
        } elseif (strlen($new) < 8) {
            $this->flash('flash_error', t('auth.password_too_short'));
        } elseif ($new !== $confirm) {
            $this->flash('flash_error', t('profile.password_mismatch'));
        } else {
            $this->users->updatePassword((int)$this->user['id'], $this->hasher->hash($new));
            $this->flash('flash_success', t('profile.password_changed'));
        }
        Response::redirect('/profile');
    }

    public function updateLocale(Request $req, array $params = []): void {
        $locale = (string)($req->post['locale'] ?? '');
        if (!in_array($locale, available_locales(), true)) {
            $this->flash('flash_error', t('errors.csrf_mismatch'));
            Response::redirect('/profile'); return;
        }
        $this->users->updateLocale((int)$this->user['id'], $locale);
        // Drop the cached resolution so the next page renders in the new locale.
        i18n_reset_cache();
        $this->flash('flash_success', t('profile.locale_saved'));
        Response::redirect('/profile');
    }

    public function updateAvatar(Request $req, array $params = []): void {
        $file = $req->files['avatar'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flash('flash_error', t('profile.avatar_hint'));
            Response::redirect('/profile'); return;
        }
        $uploader = App::make('uploader');
        $err = $uploader->validate($file);
        if ($err) { $this->flash('flash_error', $err); Response::redirect('/profile'); return; }
        if (!$uploader->isImage($file['type'] ?? '')) {
            $this->flash('flash_error', t('profile.avatar_hint'));
            Response::redirect('/profile'); return;
        }
        try {
            $stored = $uploader->store($file);
        } catch (\Throwable $e) {
            $this->flash('flash_error', $e->getMessage());
            Response::redirect('/profile'); return;
        }
        if (!empty($this->user['avatar'])) {
            $oldAbs = APP_ROOT . '/public/' . ltrim($this->user['avatar'], '/');
            if (is_file($oldAbs)) @unlink($oldAbs);
        }
        $this->users->updateAvatar((int)$this->user['id'], $stored['filename']);
        $this->flash('flash_success', t('profile.avatar_uploaded'));
        Response::redirect('/profile');
    }

    public function removeAvatar(Request $req, array $params = []): void {
        if (!empty($this->user['avatar'])) {
            $oldAbs = APP_ROOT . '/public/' . ltrim($this->user['avatar'], '/');
            if (is_file($oldAbs)) @unlink($oldAbs);
            $this->users->updateAvatar((int)$this->user['id'], null);
            $this->flash('flash_success', t('profile.avatar_removed'));
        }
        Response::redirect('/profile');
    }

    // ─── API tokens (Task 17) ────────────────────────────────────────────────
    // The /profile/tokens page lets a user mint, list, and revoke their own
    // personal-access tokens. Plaintext is shown ONCE on creation via a
    // single-use session flash; reload clears it.

    public function tokens(Request $req, array $params = []): void {
        $csrfToken = $this->csrfToken();
        $repo      = App::make('api_tokens');
        $tokens    = $repo->listForUser((int)$this->user['id']);

        // One-time reveal: stored as a structured array in the session on
        // create, then drained on the next render so a refresh hides it.
        $s = &$this->session();
        $oneTime = $s['flash_token_once'] ?? null;
        unset($s['flash_token_once']);

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'profile', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('api_tokens.title'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => t('api_tokens.title'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('profile/tokens', [
                'user'      => $this->user,
                'csrfToken' => $csrfToken,
                'tokens'    => $tokens,
                'oneTime'   => $oneTime,
                'success'   => $this->consumeFlash('flash_success'),
                'error'     => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function tokensCreate(Request $req, array $params = []): void {
        $name = trim((string)($req->post['name'] ?? ''));
        if ($name === '') {
            $this->flash('flash_error', t('api_tokens.error_name_required'));
            Response::redirect('/profile/tokens'); return;
        }
        $expiresRaw = trim((string)($req->post['expires_at'] ?? ''));
        $expiresAt  = null;
        if ($expiresRaw !== '') {
            // strtotime returns false on garbage; treat that as "no expiry"
            // rather than failing the whole submission — the user explicitly
            // typed something, but we don't want a 500.
            $ts = strtotime($expiresRaw);
            if ($ts !== false && $ts > time()) {
                $expiresAt = (int)$ts;
            }
        }
        $repo = App::make('api_tokens');
        $created = $repo->create((int)$this->user['id'], $name, $expiresAt);

        // Plaintext token is shown once and then never recoverable. Storing
        // it as an array in the session flash so the view can format it.
        $s = &$this->session();
        $s['flash_token_once'] = [
            'name'  => $name,
            'token' => $created['token'],
        ];
        Response::redirect('/profile/tokens');
    }

    public function tokensRevoke(Request $req, array $params = []): void {
        $id  = (int)($params['id'] ?? 0);
        $repo = App::make('api_tokens');
        $row = $repo->findById($id);
        // Silent no-op on missing / cross-user IDs — we don't want to leak
        // whether another user's token id exists. The redirect feedback is
        // identical to the success case.
        if ($row && (int)$row['user_id'] === (int)$this->user['id']) {
            $repo->revoke($id);
            $this->flash('flash_success', t('api_tokens.revoked'));
        }
        Response::redirect('/profile/tokens');
    }
}
