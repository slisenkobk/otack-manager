<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\ShortLinkRepository;
use App\Repository\ShortLinkVisitRepository;
use App\Service\RolePolicy;
use App\View\Renderer;

final class LinkController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private ShortLinkRepository $links,
        private ShortLinkVisitRepository $visits,
        private ActivityLogRepository $activity,
    ) {
        parent::__construct($view, $user);
        if (!RolePolicy::canManageLinks($this->user)) {
            Response::forbidden('Links are managed by admins and managers'); exit;
        }
    }

    public function index(Request $req, array $params = []): void
    {
        $query   = trim((string)($req->query['q'] ?? ''));
        $isAdmin = RolePolicy::isAdmin($this->user);
        $rows    = $this->links->listForUser((int)$this->user['id'], $isAdmin, $query);

        // Per-card aggregate counts so the list shows traffic at a glance.
        $stats = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $stats[$id] = [
                'total'  => $this->visits->countTotal($id),
                'unique' => $this->visits->countUnique($id),
            ];
        }

        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'links', 'csrfToken' => $csrf,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Links',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Links',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('links/index', [
                'links' => $rows,
                'stats' => $stats,
                'query' => $query,
            ]),
        ]));
    }

    public function show(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $link = $this->links->findById($id);
        if (!$link) { Response::notFound(); return; }
        if (!$this->canManage($link)) { Response::forbidden('Not your link'); return; }

        $stats = [
            'total'  => $this->visits->countTotal($id),
            'unique' => $this->visits->countUnique($id),
        ];
        $byDay     = $this->visits->countByDay($id, 30);
        $referers  = $this->visits->topReferers($id, 5);

        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'links', 'csrfToken' => $csrf,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Link #' . $id,
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Link #' . $id,
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('links/show', [
                'link'      => $link,
                'stats'     => $stats,
                'byDay'     => $byDay,
                'referers'  => $referers,
            ]),
        ]));
    }

    public function create(Request $req, array $params = []): void
    {
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $target = trim((string)($data['target_url'] ?? ''));
        $title  = trim((string)($data['title'] ?? ''));
        if (!ShortLinkRepository::isAllowedUrl($target)) {
            Response::json(['error' => 'Target must be a valid http(s) URL'], 422);
            return;
        }
        $id = $this->links->create($target, $title !== '' ? mb_substr($title, 0, 200) : null, (int)$this->user['id']);
        $this->activity->log('link.created', (int)$this->user['id'], null, null,
            "created short link → $target", ['link_id' => $id]);
        $link = $this->links->findById($id);
        Response::json(['ok' => true, 'id' => $id, 'slug' => $link['slug'], 'url' => '/links/' . $id]);
    }

    public function update(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $link = $this->links->findById($id);
        if (!$link) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($link)) { Response::json(['error' => 'Forbidden'], 403); return; }

        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $patch = [];
        if (array_key_exists('target_url', $data)) {
            $target = trim((string)$data['target_url']);
            if (!ShortLinkRepository::isAllowedUrl($target)) {
                Response::json(['error' => 'Target must be a valid http(s) URL'], 422);
                return;
            }
            $patch['target_url'] = $target;
        }
        if (array_key_exists('title', $data)) {
            $title = trim((string)$data['title']);
            $patch['title'] = $title !== '' ? mb_substr($title, 0, 200) : null;
        }
        $this->links->update($id, $patch);
        Response::json(['ok' => true]);
    }

    public function toggle(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $link = $this->links->findById($id);
        if (!$link) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($link)) { Response::json(['error' => 'Forbidden'], 403); return; }
        $next = (int)$link['is_disabled'] === 1 ? 0 : 1;
        $this->links->update($id, ['is_disabled' => $next]);
        Response::json(['ok' => true, 'is_disabled' => $next]);
    }

    public function rotateSlug(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $link = $this->links->findById($id);
        if (!$link) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($link)) { Response::json(['error' => 'Forbidden'], 403); return; }
        $slug = $this->links->rotateSlug($id);
        Response::json([
            'ok'   => true,
            'slug' => $slug,
            'url'  => \abs_url('/s/' . $slug),
        ]);
    }

    public function delete(Request $req, array $params): void
    {
        $id   = (int)$params['id'];
        $link = $this->links->findById($id);
        if (!$link) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($link)) { Response::json(['error' => 'Forbidden'], 403); return; }
        $this->links->delete($id);
        $this->activity->log('link.deleted', (int)$this->user['id'], null, null,
            "deleted short link /s/{$link['slug']}", ['link_id' => $id]);
        Response::json(['ok' => true]);
    }

    /** Manager sees only their own links; admin sees all. */
    private function canManage(array $link): bool
    {
        if (RolePolicy::isAdmin($this->user)) return true;
        return (int)$link['created_by'] === (int)$this->user['id'];
    }
}
