<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\PollRepository;
use App\Repository\PollVoteRepository;

/**
 * Public, unauthenticated poll voting. /p/{hash} is whitelisted from CSRF in
 * public/index.php — anti-bot defence is honeypot + HMAC time-trap + IP
 * rate-limit + the UNIQUE(poll_id, contact_normalized) one-vote-per-contact
 * rule on the DB.
 *
 * Two-stage flow:
 *   stage=contact → visitor enters email/phone
 *   stage=vote    → server signs the contact, visitor picks a choice
 *
 * The signed contact token is HMAC(poll_hash + contact_normalized + ts) keyed
 * on LOGIN_HASH — same secret reused by PublicFormController's time-trap.
 * Stateless on purpose: a refresh between stages just restarts at contact.
 */
final class PublicPollController extends BaseController
{
    private const HASH_RE          = '/^[A-Za-z0-9_-]{8,24}$/';
    private const RATE_LIMIT_PER_IP = 5;
    private const RATE_LIMIT_WINDOW = 600;
    private const HONEYPOT_FIELD   = 'email_address';
    private const MIN_FILL_SECONDS = 2;
    private const MAX_FILL_SECONDS = 3600;
    private const CONTACT_TOKEN_TTL = 3600;

    private PollRepository     $polls;
    private PollVoteRepository $votes;

    public function __construct($view, $user = null)
    {
        parent::__construct($view, $user);
        $this->polls = App::make('polls');
        $this->votes = App::make('poll_votes');
    }

    public function show(Request $req, array $params): void
    {
        $poll = $this->resolvePoll((string)($params['hash'] ?? ''));
        if (!$poll) { $this->renderNotFound(); return; }
        $unavailable = $this->unavailableReason($poll);
        if ($unavailable !== null) { $this->renderUnavailable($poll, $unavailable); return; }

        $this->applyPollLocale($poll);
        $this->renderStageContact($poll);
    }

    public function submit(Request $req, array $params): void
    {
        $poll = $this->resolvePoll((string)($params['hash'] ?? ''));
        if (!$poll) { $this->renderNotFound(); return; }
        $unavailable = $this->unavailableReason($poll);
        if ($unavailable !== null) { $this->renderUnavailable($poll, $unavailable); return; }

        $this->applyPollLocale($poll);

        // Honeypot — silent 200 with the standard thanks page so bots see no signal.
        if ((string)($req->post[self::HONEYPOT_FIELD] ?? '') !== '') {
            $this->renderThanks($poll);
            return;
        }

        // Time-trap — silent thanks on tampering (defence in depth, not the primary gate).
        if (!$this->verifyTimeTrap($req, (string)$poll['hash'])) {
            $this->renderThanks($poll);
            return;
        }

        // Stage 2 dispatches on the presence of a `choice` field; everything
        // else is treated as a stage 1 contact submission.
        if (isset($req->post['choice'])) {
            $this->handleVote($req, $poll);
            return;
        }
        $this->handleContact($req, $poll);
    }

    // ─── stage 1: contact ───────────────────────────────────────────────────

    private function handleContact(Request $req, array $poll): void
    {
        $remoteIp = $this->resolveRemoteIp();
        if ($this->rateLimited($remoteIp)) { $this->renderRateLimited(); return; }

        $contactKind = (string)($poll['contact_field'] ?? PollRepository::CONTACT_EMAIL);
        $contactRaw  = trim((string)($req->post['contact'] ?? ''));
        $err         = $this->validateContact($contactRaw, $contactKind);
        $contactNorm = PollRepository::normalizeContact($contactRaw, $contactKind);
        if ($contactNorm === '') {
            $err = $err ?? t('public_poll.contact_required');
        }
        if ($err !== null) {
            $this->renderStageContact($poll, ['contact' => $contactRaw], $err);
            return;
        }

        // Surface a clean "you already voted" rather than wait for the UNIQUE
        // failure at vote time — better UX, and matches what the admin sees in
        // the voters tab.
        if ($this->votes->hasVoted((int)$poll['id'], $contactNorm)) {
            $this->renderAlreadyVoted($poll);
            return;
        }

        $this->renderStageVote($poll, $contactRaw, $contactNorm);
    }

    // ─── stage 2: vote ──────────────────────────────────────────────────────

    private function handleVote(Request $req, array $poll): void
    {
        $remoteIp = $this->resolveRemoteIp();
        if ($this->rateLimited($remoteIp)) { $this->renderRateLimited(); return; }

        $contactRaw  = trim((string)($req->post['contact'] ?? ''));
        $token       = (string)($req->post['contact_token'] ?? '');
        $tokenTs     = (int)($req->post['contact_token_ts'] ?? 0);
        // Re-derive the normalized contact server-side rather than trusting the
        // round-tripped value. The token signs whatever normalization stage 1
        // produced, so we recompute the same way here and verify the token
        // against it. This eliminates a class of "stage-2 says one thing, the
        // DB stores another" bugs if normalization rules ever change.
        $contactKind = (string)($poll['contact_field'] ?? PollRepository::CONTACT_EMAIL);
        $contactNorm = PollRepository::normalizeContact($contactRaw, $contactKind);

        if (!$this->verifyContactToken($poll, $contactNorm, $tokenTs, $token)) {
            // Tampered or expired token → restart at stage 1.
            $this->renderStageContact($poll, [], t('public_poll.session_expired'));
            return;
        }

        $choiceKey = (string)$req->post['choice'];
        $fields    = json_decode((string)$poll['fields_json'], true) ?: [];
        if (!$this->isValidChoice($fields, $choiceKey)) {
            // Re-render stage 2 with a fresh token so the visitor can try again.
            $this->renderStageVote($poll, $contactRaw, $contactNorm, t('public_poll.pick_one'));
            return;
        }

        $result = $this->votes->record(
            (int)$poll['id'],
            $contactRaw !== '' ? $contactRaw : $contactNorm,
            $contactNorm,
            $choiceKey,
            $remoteIp !== '' ? $remoteIp : null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') !== '' ? mb_substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : null
        );
        if (!$result['ok']) {
            // Either duplicate (someone voted between stage 1 + 2) or another
            // PDO failure surfaced. Treat duplicate as "already voted"; rethrow
            // everything else so the global handler sees real bugs.
            if (($result['reason'] ?? null) === 'duplicate') {
                $this->renderAlreadyVoted($poll);
                return;
            }
            throw new \RuntimeException('vote.record failed');
        }

        App::make('activity')->log(
            'poll.voted',
            (int)$poll['created_by'],
            null,
            null,
            'new vote on "' . $poll['title'] . '"',
            ['poll_id' => (int)$poll['id'], 'external' => true]
        );
        App::make('events')->fire('poll.voted', [
            'poll_id'    => (int)$poll['id'],
            'poll_title' => $poll['title'],
            'choice_key' => $choiceKey,
        ]);

        $this->renderThanks($poll);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function resolvePoll(string $hash): ?array
    {
        if (!preg_match(self::HASH_RE, $hash)) return null;
        return $this->polls->findByHash($hash);
    }

    /**
     * Returns null if the poll can accept votes right now, otherwise one of
     * 'draft' / 'closed' so the template can pick a tailored message.
     */
    private function unavailableReason(array $poll): ?string
    {
        $status = (string)$poll['status'];
        if ($status === PollRepository::STATUS_ACTIVE) return null;
        return $status === PollRepository::STATUS_CLOSED ? 'closed' : 'draft';
    }

    private function applyPollLocale(array $poll): void
    {
        $locale = (string)($poll['locale'] ?? '');
        if ($locale !== '' && in_array($locale, available_locales(), true)) {
            $GLOBALS['__i18n_locale_resolved__'] = $locale;
            unset($GLOBALS['__i18n_catalog__'], $GLOBALS['__i18n_locale__']);
        }
    }

    private function timeTrapSecret(): string
    {
        $s = (string)App::env('LOGIN_HASH', '');
        return $s !== '' ? $s : 'otack-poll-time-trap-fallback';
    }

    private function timeTrapTokens(string $pollHash): array
    {
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '|' . $pollHash, $this->timeTrapSecret());
        return ['ts' => $ts, 'sig' => $sig];
    }

    private function verifyTimeTrap(Request $req, string $pollHash): bool
    {
        $ts  = (int)($req->post['ts'] ?? 0);
        $sig = (string)($req->post['ts_sig'] ?? '');
        $expected = hash_hmac('sha256', $ts . '|' . $pollHash, $this->timeTrapSecret());
        $now = time();
        if ($ts === 0 || !hash_equals($expected, $sig)) return false;
        if (($now - $ts) < self::MIN_FILL_SECONDS) return false;
        if (($now - $ts) > self::MAX_FILL_SECONDS) return false;
        return true;
    }

    private function signContactToken(array $poll, string $contactNorm, int $ts): string
    {
        return hash_hmac('sha256', $ts . '|' . $poll['hash'] . '|' . $contactNorm, $this->timeTrapSecret());
    }

    private function verifyContactToken(array $poll, string $contactNorm, int $ts, string $sig): bool
    {
        if ($contactNorm === '' || $ts <= 0 || $sig === '') return false;
        if ((time() - $ts) > self::CONTACT_TOKEN_TTL) return false;
        $expected = $this->signContactToken($poll, $contactNorm, $ts);
        return hash_equals($expected, $sig);
    }

    private function validateContact(string $value, string $kind): ?string
    {
        if ($value === '') return t('public_poll.contact_required');
        if ($kind === PollRepository::CONTACT_PHONE) {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
            if (strlen($digits) < 6 || strlen($digits) > 20) return t('public_poll.invalid_phone');
            return null;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? null : t('public_poll.invalid_email');
    }

    private function isValidChoice(array $fields, string $key): bool
    {
        foreach ($fields as $f) {
            if (($f['key'] ?? '') !== 'choice') continue;
            foreach ((array)($f['options'] ?? []) as $opt) {
                if (is_array($opt) && (string)($opt['key'] ?? '') === $key) return true;
            }
        }
        return false;
    }

    private function resolveRemoteIp(): string
    {
        return Request::clientIp();
    }

    /**
     * Rate-limit: count poll votes from this IP across all polls in the window.
     * Uses an inline SQL count rather than a repo method to keep PollVoteRepository
     * focused on per-poll semantics.
     */
    private function rateLimited(string $remoteIp): bool
    {
        if ($remoteIp === '') return false;
        $pdo = App::make('db');
        $cutoff = (new \DateTimeImmutable('-' . self::RATE_LIMIT_WINDOW . ' seconds'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM poll_votes WHERE remote_ip = ? AND created_at >= ?');
        $stmt->execute([$remoteIp, $cutoff]);
        return (int)$stmt->fetchColumn() >= self::RATE_LIMIT_PER_IP;
    }

    // ─── render helpers ─────────────────────────────────────────────────────

    private function renderStageContact(array $poll, array $values = [], ?string $error = null): void
    {
        $trap = $this->timeTrapTokens((string)$poll['hash']);
        Response::html($this->view->render('public/poll', [
            'stage'         => 'contact',
            'poll'          => $poll,
            'trap'          => $trap,
            'honeypot'      => self::HONEYPOT_FIELD,
            'values'        => $values,
            'error'         => $error,
            'contactField'  => (string)($poll['contact_field'] ?? PollRepository::CONTACT_EMAIL),
            'choiceField'   => null,
            'contactToken'  => null,
        ], null));
    }

    private function renderStageVote(array $poll, string $contactRaw, string $contactNorm, ?string $error = null): void
    {
        $trap   = $this->timeTrapTokens((string)$poll['hash']);
        $ts     = time();
        $token  = $this->signContactToken($poll, $contactNorm, $ts);
        $fields = json_decode((string)$poll['fields_json'], true) ?: [];
        $choiceField = null;
        foreach ($fields as $f) { if (($f['key'] ?? '') === 'choice') { $choiceField = $f; break; } }
        Response::html($this->view->render('public/poll', [
            'stage'        => 'vote',
            'poll'         => $poll,
            'trap'         => $trap,
            'honeypot'     => self::HONEYPOT_FIELD,
            'error'        => $error,
            'contactField' => (string)($poll['contact_field'] ?? PollRepository::CONTACT_EMAIL),
            'choiceField'  => $choiceField,
            'contactRaw'   => $contactRaw,
            'contactNorm'  => $contactNorm,
            'contactToken' => $token,
            'contactTokenTs' => $ts,
        ], null));
    }

    private function renderThanks(array $poll): void
    {
        Response::html($this->view->render('public/poll-thanks', [
            'poll' => $poll,
        ], null));
    }

    private function renderAlreadyVoted(array $poll): void
    {
        Response::html($this->view->render('public/poll-thanks', [
            'poll'    => $poll,
            'variant' => 'already-voted',
        ], null));
    }

    private function renderNotFound(): void
    {
        Response::html($this->view->render('public/poll-not-found', [], null));
    }

    private function renderUnavailable(array $poll, string $reason): void
    {
        Response::html($this->view->render('public/poll-not-found', [
            'poll'   => $poll,
            'reason' => $reason,
        ], null));
    }

    private function renderRateLimited(): void
    {
        http_response_code(429);
        Response::html(
            '<!doctype html><meta charset="utf-8"><title>Too many requests</title>'
            . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#F5F0E6;color:#1A1612;}'
            . 'div{max-width:520px;text-align:center;padding:32px;}</style>'
            . '<div><h1>Slow down</h1><p>You\'ve voted from this address recently. Try again in a few minutes.</p></div>'
        );
    }
}
