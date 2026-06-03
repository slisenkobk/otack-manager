<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\AuthGuard;
use App\Repository\TagRepository;
use App\View\Renderer;

final class TagAdminController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
        private TagRepository $tags,
        private Csrf $csrf,
    ) {
        parent::__construct($view, $user);
        AuthGuard::requireAdmin($this->user);
    }

    public function index(Request $req, array $params = []): void {
        $page    = max(1, (int)($req->query['page'] ?? 1));
        $query   = trim((string)($req->query['q'] ?? ''));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $allTags = $this->tags->listPaged($perPage, $offset, $query);
        $total   = $this->tags->countAll($query);
        $pages   = max(1, (int)ceil($total / $perPage));
        $usages  = [];
        foreach ($allTags as $tag) {
            $usages[(int)$tag['id']] = $this->tags->countUsage((int)$tag['id']);
        }
        $csrfToken = $this->csrf->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'tags', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Tags',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Tags',
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('tags/index', [
                'tags'   => $allTags,
                'usages' => $usages,
                'page'   => $page,
                'pages'  => $pages,
                'total'  => $total,
                'query'  => $query,
            ]),
        ]));
    }

    public function update(Request $req, array $params): void {
        $id   = (int)$params['id'];
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $tag  = $this->tags->findById($id);
        if (!$tag) { Response::json(['error' => 'Not found'], 404); return; }
        $fields = [];
        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '') { Response::json(['error' => 'Name cannot be empty'], 422); return; }
            $fields['name'] = $name;
        }
        if (isset($data['color'])) {
            $fields['color'] = (string)$data['color'];
        }
        $this->tags->update($id, $fields);
        Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): void {
        $id  = (int)$params['id'];
        $tag = $this->tags->findById($id);
        if (!$tag) { Response::json(['error' => 'Not found'], 404); return; }
        $this->tags->delete($id);
        Response::json(['ok' => true]);
    }
}
