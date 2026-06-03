<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ShortLinkRepository;
use App\Repository\ShortLinkVisitRepository;
use App\View\Renderer;

/**
 * Public short-link proxy. No auth, no CSRF (whitelisted in public/index.php).
 * Logs the visit, then 302s to the link's target URL.
 */
final class PublicLinkController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private ShortLinkRepository $links,
        private ShortLinkVisitRepository $visits,
    ) {
        parent::__construct($view, $user);
    }

    /** Slug shape mirrors ShortLinkRepository::generateSlug — 8 base64url chars.
     *  Upper bound 24 leaves room for any future longer-slug option without code
     *  changes; we don't accept shorter than 8 to avoid trivial guessing. */
    private const SLUG_RE = '/^[A-Za-z0-9_-]{8,24}$/';

    public function redirect(Request $req, array $params): void
    {
        $slug = (string)($params['slug'] ?? '');
        if (!preg_match(self::SLUG_RE, $slug)) {
            $this->renderNotFound(); return;
        }
        $link = $this->links->findActiveBySlug($slug);
        if (!$link) { $this->renderNotFound(); return; }

        $ip      = $this->resolveRemoteIp();
        $secret  = (string)\App\App::env('LOGIN_HASH', '');
        $ipHash  = ShortLinkVisitRepository::hashIp($ip, $secret);
        $ua      = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $referer = (string)($_SERVER['HTTP_REFERER']    ?? '');

        // Logging must never block the redirect itself — a malformed request
        // (truncated DB, locked sqlite) should still send the visitor along.
        try {
            $this->visits->log(
                (int)$link['id'],
                $ipHash,
                $ua !== '' ? $ua : null,
                $referer !== '' ? $referer : null
            );
        } catch (\Throwable $e) {
            error_log('[short-link] visit log failed: ' . $e->getMessage());
        }

        Response::redirect((string)$link['target_url'], 302);
    }

    private function resolveRemoteIp(): string
    {
        return Request::clientIp();
    }

    private function renderNotFound(): void
    {
        // Response::html() defaults to 200 status; pass 404 explicitly so the
        // browser / crawler sees the right code for a broken / disabled link.
        Response::html(
            '<!doctype html><meta charset="utf-8"><title>Link not found</title>'
            . '<style>body{font-family:system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#F5F0E6;color:#1A1612;}'
            . 'div{max-width:520px;text-align:center;padding:32px;}</style>'
            . '<div><h1>Link not found</h1><p>This short link does not exist or has been disabled.</p></div>',
            404
        );
    }
}
