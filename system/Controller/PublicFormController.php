<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\SettingsRepository;

/**
 * Public, unauthenticated form rendering / submission. No CSRF token —
 * routes are whitelisted in public/index.php to skip CSRF.
 */
final class PublicFormController extends BaseController
{
    private FormRepository           $forms;
    private FormSubmissionRepository $subs;
    private SettingsRepository       $settings;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->forms    = App::make('forms');
        $this->subs     = App::make('form_submissions');
        $this->settings = App::make('settings');
    }

    /** Hash format expected from the public URL — base64url alphabet, length 8..24. */
    private const HASH_RE = '/^[A-Za-z0-9_-]{8,24}$/';

    /** Anti-spam window for anonymous POSTs. */
    private const RATE_LIMIT_PER_IP = 5;
    private const RATE_LIMIT_WINDOW = 600;

    /** Honeypot field name — bots fill any input named "email", humans don't see it. */
    private const HONEYPOT_FIELD = 'email_address';

    /** Anti-bot time-trap: reject submissions that arrive sooner than this. */
    private const MIN_FILL_SECONDS = 2;
    /** Stale-form ceiling — replays older than this are rejected too. */
    private const MAX_FILL_SECONDS = 3600;

    private function timeTrapSecret(): string
    {
        // Reuse LOGIN_HASH as a shared HMAC secret — it's already required to
        // be set in env and stays stable across requests. Falls back to a fixed
        // string only in test environments that don't set it; that's fine
        // because the time trap is defence-in-depth, not the primary gate.
        $s = (string)\App\App::env('LOGIN_HASH', '');
        return $s !== '' ? $s : 'otack-form-time-trap-fallback';
    }

    /**
     * Generate a signed timestamp pair the form embeds as hidden fields.
     * Signing prevents the bot from replaying or forging "ts" without also
     * forging a matching sha256 keyed on a server-only secret.
     *
     * @return array{ts:int, sig:string}
     */
    public function timeTrapTokens(string $formHash): array
    {
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '|' . $formHash, $this->timeTrapSecret());
        return ['ts' => $ts, 'sig' => $sig];
    }

    public function show(Request $req, array $params): void {
        $hash = (string)($params['hash'] ?? '');
        if (!preg_match(self::HASH_RE, $hash)) {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $form = $this->forms->findByHash($hash);
        if (!$form || $form['status'] === 'archived') {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $this->applyFormLocale($form);
        $fields  = $this->decodeFields($form);
        $contact = $this->resolveContactBlock($form);
        $trap    = $this->timeTrapTokens($form['hash']);
        Response::html($this->view->render('public/form', [
            'honeypot'  => self::HONEYPOT_FIELD,
            'trap'      => $trap,
            'form'    => $form,
            'fields'  => $fields,
            'contact' => $contact,
            'sent'    => false,
        ], null));
    }

    /**
     * Pin the request-scoped locale to whatever the form-author picked. The
     * visitor's Accept-Language and the admin's user.locale are deliberately
     * ignored here — the form is meant to be filled in the language the
     * author chose, not in whatever the visitor's browser happens to prefer.
     */
    private function applyFormLocale(array $form): void
    {
        $locale = (string)($form['locale'] ?? '');
        if ($locale !== '' && in_array($locale, available_locales(), true)) {
            $GLOBALS['__i18n_locale_resolved__'] = $locale;
            unset($GLOBALS['__i18n_catalog__'], $GLOBALS['__i18n_locale__']);
        }
    }

    public function submit(Request $req, array $params): void {
        $hash = (string)($params['hash'] ?? '');
        if (!preg_match(self::HASH_RE, $hash)) {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $form = $this->forms->findByHash($hash);
        if (!$form || $form['status'] === 'archived') {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $this->applyFormLocale($form);
        // Anti-bot honeypot: a hidden input that real browsers leave blank.
        // Bots that scrape forms tend to fill every field, especially ones
        // named "email"-ish. We return 200 OK without recording anything so
        // the bot believes it succeeded — no signal back, no retry.
        $honeypotValue = (string)($req->post[self::HONEYPOT_FIELD] ?? '');
        if ($honeypotValue !== '') {
            Response::html($this->view->render('public/form-thanks', ['form' => $form], null));
            return;
        }

        // Anti-bot time-trap: signed timestamp embedded at render. A bot that
        // submits within 2s of GET clearly didn't read the form. Forging the
        // signature requires the server-side LOGIN_HASH, so simple replay is
        // a non-starter. Stale ts (>1h) is also rejected to bound replay
        // windows. Soft-fail: silent 200 (same logic as honeypot).
        $ts  = (int)($req->post['ts'] ?? 0);
        $sig = (string)($req->post['ts_sig'] ?? '');
        $expected = hash_hmac('sha256', $ts . '|' . $form['hash'], $this->timeTrapSecret());
        $now = time();
        if ($ts === 0 || !hash_equals($expected, $sig)
            || ($now - $ts) < self::MIN_FILL_SECONDS
            || ($now - $ts) > self::MAX_FILL_SECONDS) {
            Response::html($this->view->render('public/form-thanks', ['form' => $form], null));
            return;
        }

        // Anti-spam: throttle per IP. Behind a reverse proxy we trust
        // X-Forwarded-For's first hop; otherwise REMOTE_ADDR.
        $remoteIp = $this->resolveRemoteIp();
        if ($remoteIp !== '' && $this->subs->countRecentByIp($remoteIp, self::RATE_LIMIT_WINDOW) >= self::RATE_LIMIT_PER_IP) {
            http_response_code(429);
            Response::html(
                '<!doctype html><meta charset="utf-8"><title>Too many submissions</title>'
                . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#F5F0E6;color:#1A1612;}'
                . 'div{max-width:520px;text-align:center;padding:32px;}</style>'
                . '<div><h1>Slow down</h1><p>You\'ve submitted this form a few times already. Please try again in 10 minutes.</p></div>'
            );
            return;
        }

        $fields = $this->decodeFields($form);
        $data   = [];
        $errors = [];
        foreach ($fields as $f) {
            $raw = $req->post[$f['key']] ?? null;
            if ($f['type'] === 'checkbox') {
                $raw = is_array($raw) ? array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== '')) : [];
            } else {
                $raw = is_string($raw) ? trim($raw) : '';
            }
            $isEmpty = ($f['type'] === 'checkbox') ? empty($raw) : ($raw === '');
            if (!empty($f['required']) && $isEmpty) {
                $errors[$f['key']] = 'Required';
            }
            if (!$isEmpty && $f['type'] === 'email' && !filter_var((string)$raw, FILTER_VALIDATE_EMAIL)) {
                $errors[$f['key']] = 'Invalid email';
            }
            $data[$f['key']] = $raw;
        }

        if ($errors) {
            $contact = $this->resolveContactBlock($form);
            // Re-arm honeypot + time-trap on validation error so the user can
            // resubmit cleanly. Fresh tokens each retry keep the bounds tight.
            $trap = $this->timeTrapTokens($form['hash']);
            Response::html($this->view->render('public/form', [
                'honeypot'   => self::HONEYPOT_FIELD,
                'trap'       => $trap,
                'form'       => $form,
                'fields'     => $fields,
                'contact'    => $contact,
                'errors'     => $errors,
                'values'     => $data,
                'sent'       => false,
            ], null));
            return;
        }

        // Submission no longer carries user-edited footer data — the contact
        // block on the public form is read-only display only.
        $submissionId = $this->subs->create((int)$form['id'], $data, [], $remoteIp !== '' ? $remoteIp : null);

        // Activity feed: external submissions don't have an actor_id, so we
        // attribute to the form owner (created_by) so admins/managers see it
        // in their scope. The "external" flag in meta keeps downstream code
        // honest about who really filed it.
        $ownerId = (int)$form['created_by'];
        $preview = mb_substr(implode(' · ', array_filter(array_map(
            fn($v) => is_array($v) ? implode(',', $v) : (string)$v,
            $data
        ), fn($s) => trim($s) !== '')), 0, 160);
        \App\App::make('activity')->log(
            'form.submitted',
            $ownerId,
            null,
            null,
            'new submission on "' . $form['title'] . '"' . ($preview !== '' ? ' — ' . $preview : ''),
            ['form_id' => (int)$form['id'], 'submission_id' => $submissionId, 'external' => true]
        );

        // Fire the Telegram event with an IMPORTANT marker — these are
        // customer touchpoints, more urgent than internal task chatter.
        \App\App::make('events')->fire('form.submitted', [
            'form_id'       => (int)$form['id'],
            'form_title'    => $form['title'],
            'submission_id' => $submissionId,
            'preview'       => $preview,
            'url'           => '/forms-data/' . $submissionId,
        ]);

        Response::html($this->view->render('public/form-thanks', [
            'form' => $form,
        ], null));
    }

    /**
     * Best-effort visitor IP. Honours X-Forwarded-For first hop only.
     * Returns '' if nothing usable is in the request.
     */
    private function resolveRemoteIp(): string {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $first = trim(strtok($xff, ','));
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        $ra = $_SERVER['REMOTE_ADDR'] ?? '';
        return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '';
    }

    private function decodeFields(array $form): array {
        $fields = json_decode((string)$form['fields_json'], true);
        return is_array($fields) ? $fields : [];
    }

    private function decodeFooter(array $form): array {
        $footer = json_decode((string)$form['footer_json'], true);
        return is_array($footer) ? $footer : [];
    }

    /**
     * Returns a normalised list of contact lines to render on the public form
     * footer. Per-form overrides win over the global Settings values.
     *
     * Shape: [ ['label' => '…', 'value' => '…', 'kind' => 'company|email|phone|address|note'], ... ]
     */
    private function resolveContactBlock(array $form): array {
        $footer  = $this->decodeFooter($form);
        $globals = $this->settings->getMany(
            ['contact_company_name', 'contact_email', 'contact_phone', 'contact_address', 'contact_default_text'],
            []
        );
        $pick = function (string $key, string $globalKey) use ($footer, $globals): string {
            $local = trim((string)($footer[$key] ?? ''));
            if ($local !== '') return $local;
            return trim((string)($globals[$globalKey] ?? ''));
        };
        $rows = [];
        if (!empty($footer['show_company'])) {
            $v = $pick('company_name', 'contact_company_name');
            if ($v !== '') $rows[] = ['label' => 'Company', 'value' => $v, 'kind' => 'company'];
        }
        if (!empty($footer['show_email'])) {
            $v = $pick('email', 'contact_email');
            if ($v !== '') $rows[] = ['label' => 'Email', 'value' => $v, 'kind' => 'email'];
        }
        if (!empty($footer['show_phone'])) {
            $v = $pick('phone', 'contact_phone');
            if ($v !== '') $rows[] = ['label' => 'Phone', 'value' => $v, 'kind' => 'phone'];
        }
        if (!empty($footer['show_address'])) {
            $v = $pick('address', 'contact_address');
            if ($v !== '') $rows[] = ['label' => 'Address', 'value' => $v, 'kind' => 'address'];
        }
        if (!empty($footer['show_note'])) {
            $v = $pick('note', 'contact_default_text');
            if ($v !== '') $rows[] = ['label' => 'Note', 'value' => $v, 'kind' => 'note'];
        }
        return $rows;
    }
}
