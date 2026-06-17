<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\AuthGuard;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\CommentRepository;
use App\Repository\KnowledgeCategoryRepository;
use App\Repository\KnowledgePageRepository;
use App\Repository\KnowledgePageVersionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Service\EventBus;
use App\Service\Markdown;
use App\View\Renderer;

/**
 * Knowledge-base controller. Endpoints:
 *
 *   GET  /knowledge                          — list + filters
 *   GET  /knowledge/new                      — editor (new)
 *   POST /knowledge                          — create
 *   GET  /knowledge/{slug}                   — show
 *   GET  /knowledge/{slug}/edit              — editor (existing)
 *   POST /knowledge/{slug}                   — update
 *   POST /knowledge/{slug}/delete            — delete (admin only)
 *   POST /knowledge/{slug}/snapshot          — append a version snapshot
 *   GET  /knowledge/{slug}/versions          — version journal
 *   GET  /knowledge/{slug}/versions/{vid}    — single version
 *   POST /knowledge/preview                  — render markdown → HTML (JSON)
 *   POST /knowledge/{slug}/comments          — add a comment
 *   POST /knowledge/{slug}/comments/{cid}/delete — delete comment
 *
 *   GET  /admin/knowledge/categories         — manage categories (admin)
 *   POST /admin/knowledge/categories         — create
 *   POST /admin/knowledge/categories/{id}    — rename
 *   POST /admin/knowledge/categories/{id}/delete — delete
 *
 * Permissions:
 *   Read              — any approved user
 *   Create/edit/snapshot — admin + manager
 *   Delete page        — admin only
 *   Comment            — any approved user
 *   Delete comment     — author or admin
 *   Manage categories  — admin
 */
final class KnowledgeController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private KnowledgePageRepository $pages,
        private KnowledgeCategoryRepository $categories,
        private KnowledgePageVersionRepository $versions,
        private TagRepository $tags,
        private CommentRepository $comments,
        private UserRepository $users,
        private ActivityLogRepository $activity,
        private EventBus $events,
        private Csrf $csrf,
        private object $session,
    ) {
        parent::__construct($view, $user);
    }

    private function &session(): array { return $this->session->store; }

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

    /** Editor / snapshot permission — admin or manager. */
    private function requireEditor(): void
    {
        $role = $this->user['role'] ?? '';
        if ($role !== 'admin' && $role !== 'manager') {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>403 Forbidden</h1>';
            exit;
        }
    }

    // ─── List / filters ──────────────────────────────────────────────

    public function index(Request $req, array $params = []): void
    {
        $categorySlug = trim((string)($req->query['category'] ?? ''));
        $tagId        = (int)($req->query['tag'] ?? 0) ?: null;
        $q            = trim((string)($req->query['q'] ?? ''));
        $page         = max(1, (int)($req->query['page'] ?? 1));
        $perPage      = 30;

        $categoryId = null;
        if ($categorySlug !== '') {
            $cat = $this->categories->findBySlug($categorySlug);
            if ($cat) $categoryId = (int)$cat['id'];
        }

        $result = $this->pages->listFiltered($categoryId, $tagId, $q, $page, $perPage);

        $csrfToken = $this->csrfToken();
        $allCats   = $this->categories->listAll();
        $allTags   = $this->tags->listForScope('knowledge_page');
        $counts    = $this->pages->categoryCounts();

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('knowledge.title'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => t('knowledge.title'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/index', [
                'user'         => $this->user,
                'rows'         => $result['rows'],
                'total'        => $result['total'],
                'page'         => $page,
                'perPage'      => $perPage,
                'categories'   => $allCats,
                'counts'       => $counts,
                'tags'         => $allTags,
                'categorySlug' => $categorySlug,
                'tagId'        => $tagId,
                'q'            => $q,
                'canEdit'      => in_array($this->user['role'] ?? '', ['admin','manager'], true),
                'csrfToken'    => $csrfToken,
                'success'      => $this->consumeFlash('flash_success'),
                'error'        => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    // ─── Show ────────────────────────────────────────────────────────

    public function show(Request $req, array $params): void
    {
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }

        $csrfToken    = $this->csrfToken();
        $bodyHtml     = Markdown::renderRich((string)($page['body_md'] ?? ''));
        $pageTags     = $this->tags->listForKnowledgePage((int)$page['id']);
        $pageComments = $this->comments->listFor('knowledge_page', (int)$page['id']);
        $versionsCount = $this->versions->countForPage((int)$page['id']);

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $page['title'],
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $page['title'],
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/show', [
                'user'          => $this->user,
                'page'          => $page,
                'bodyHtml'      => $bodyHtml,
                'tags'          => $pageTags,
                'comments'      => $pageComments,
                'versionsCount' => $versionsCount,
                'canEdit'       => in_array($this->user['role'] ?? '', ['admin','manager'], true),
                'csrfToken'     => $csrfToken,
                'success'       => $this->consumeFlash('flash_success'),
                'error'         => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    // ─── Editor ──────────────────────────────────────────────────────

    public function newForm(Request $req, array $params = []): void
    {
        $this->requireEditor();
        $this->renderEditor(null);
    }

    public function editForm(Request $req, array $params): void
    {
        $this->requireEditor();
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }
        $this->renderEditor($page);
    }

    private function renderEditor(?array $page): void
    {
        $csrfToken  = $this->csrfToken();
        $cats       = $this->categories->listAll();
        $allTags    = $this->tags->listForScope('knowledge_page');
        $pageTags   = $page ? $this->tags->listForKnowledgePage((int)$page['id']) : [];

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user,
            'crumb' => $page ? $page['title'] : t('knowledge.new'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $page ? $page['title'] : t('knowledge.new'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/edit', [
                'user'       => $this->user,
                'page'       => $page,
                'categories' => $cats,
                'allTags'    => $allTags,
                'pageTags'   => $pageTags,
                'csrfToken'  => $csrfToken,
                'error'      => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function create(Request $req, array $params = []): void
    {
        $this->requireEditor();
        $title  = trim((string)($req->post['title']      ?? ''));
        $bodyMd = (string)($req->post['body_md']         ?? '');
        $catId  = (int)($req->post['category_id']        ?? 0) ?: null;
        $tagIds = array_map('intval', (array)($req->post['tag_ids'] ?? []));
        if ($title === '') {
            $this->flash('flash_error', t('knowledge.error.title_required'));
            Response::redirect('/knowledge/new'); return;
        }
        $id = $this->pages->create($title, $bodyMd, $catId, (int)$this->user['id']);
        foreach (array_unique($tagIds) as $tagId) {
            if ($tagId > 0) $this->tags->attachToKnowledgePage($id, $tagId);
        }

        $page = $this->pages->findById($id);
        $this->activity->log([
            'event'      => 'knowledge.page.created',
            'actor_id'   => (int)$this->user['id'],
            'project_id' => null,
            'task_id'    => null,
            'summary'    => $title,
            'meta'       => ['page_id' => $id, 'slug' => $page['slug']],
        ]);
        $this->flash('flash_success', t('knowledge.flash.created'));
        Response::redirect('/knowledge/' . rawurlencode((string)$page['slug']));
    }

    public function update(Request $req, array $params): void
    {
        $this->requireEditor();
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }

        $title  = trim((string)($req->post['title']  ?? ''));
        $bodyMd = (string)($req->post['body_md']     ?? '');
        $catId  = (int)($req->post['category_id']    ?? 0) ?: null;
        $tagIds = array_map('intval', (array)($req->post['tag_ids'] ?? []));
        if ($title === '') {
            $this->flash('flash_error', t('knowledge.error.title_required'));
            Response::redirect('/knowledge/' . rawurlencode($slug) . '/edit'); return;
        }
        $this->pages->update((int)$page['id'], $title, $bodyMd, $catId);

        // Tag sync: drop everything not in $tagIds, attach anything new.
        $current  = $this->tags->listForKnowledgePage((int)$page['id']);
        $currentIds = array_map(fn($t) => (int)$t['id'], $current);
        foreach ($currentIds as $cid) {
            if (!in_array($cid, $tagIds, true)) {
                $this->tags->detachFromKnowledgePage((int)$page['id'], $cid);
            }
        }
        foreach (array_unique($tagIds) as $tid) {
            if ($tid > 0 && !in_array($tid, $currentIds, true)) {
                $this->tags->attachToKnowledgePage((int)$page['id'], $tid);
            }
        }

        $this->flash('flash_success', t('knowledge.flash.updated'));
        Response::redirect('/knowledge/' . rawurlencode($slug));
    }

    public function delete(Request $req, array $params): void
    {
        AuthGuard::requireAdmin($this->user);
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }
        $this->pages->delete((int)$page['id']);
        $this->flash('flash_success', t('knowledge.flash.deleted'));
        Response::redirect('/knowledge');
    }

    // ─── Snapshots ───────────────────────────────────────────────────

    public function snapshot(Request $req, array $params): void
    {
        $this->requireEditor();
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }
        $note = trim((string)($req->post['note'] ?? ''));
        if ($note === '') $note = null;
        $this->versions->append(
            (int)$page['id'],
            (string)$page['title'],
            (string)($page['body_md'] ?? ''),
            $note,
            (int)$this->user['id']
        );
        $this->flash('flash_success', t('knowledge.flash.snapshot_saved'));
        Response::redirect('/knowledge/' . rawurlencode($slug) . '/versions');
    }

    public function versionsList(Request $req, array $params): void
    {
        $slug = (string)($params['slug'] ?? '');
        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::notFound(); return; }
        $csrfToken = $this->csrfToken();
        $list = $this->versions->listForPage((int)$page['id']);

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $page['title'] . ' · ' . t('knowledge.versions'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $page['title'] . ' — ' . t('knowledge.versions'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/versions', [
                'page'      => $page,
                'versions'  => $list,
                'csrfToken' => $csrfToken,
                'canEdit'   => in_array($this->user['role'] ?? '', ['admin','manager'], true),
            ]),
        ]));
    }

    public function versionShow(Request $req, array $params): void
    {
        $slug = (string)($params['slug'] ?? '');
        $vid  = (int)($params['vid'] ?? 0);
        $page = $this->pages->findBySlug($slug);
        $version = $vid > 0 ? $this->versions->findById($vid) : null;
        if (!$page || !$version || (int)$version['page_id'] !== (int)$page['id']) {
            Response::notFound(); return;
        }
        $csrfToken = $this->csrfToken();
        $bodyHtml  = Markdown::renderRich((string)($version['body_md'] ?? ''));

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $page['title'] . ' · #' . $version['id'],
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $page['title'] . ' — #' . $version['id'],
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/version-show', [
                'page'     => $page,
                'version'  => $version,
                'bodyHtml' => $bodyHtml,
            ]),
        ]));
    }

    // ─── Editor preview endpoint ─────────────────────────────────────

    public function preview(Request $req, array $params = []): void
    {
        $data = $req->jsonBody([]);
        $md   = (string)($data['body_md'] ?? '');
        Response::json([
            'ok'        => true,
            'body_html' => Markdown::renderRich($md),
        ]);
    }

    // ─── Comments ────────────────────────────────────────────────────

    public function commentCreate(Request $req, array $params): void
    {
        $data     = $req->jsonBody([]);
        $slug     = (string)($params['slug'] ?? '');
        $body     = trim((string)($data['body'] ?? ''));
        $parentId = isset($data['parent_id']) && $data['parent_id'] ? (int)$data['parent_id'] : null;

        $page = $this->pages->findBySlug($slug);
        if (!$page) { Response::json(['error' => 'Not found'], 404); return; }
        if ($body === '') { Response::json(['error' => 'Empty body'], 422); return; }

        // Reply parent must belong to the same page; collapse to root.
        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);
            if (!$parent
                || (string)$parent['entity_type'] !== 'knowledge_page'
                || (int)$parent['entity_id'] !== (int)$page['id']
            ) {
                $parentId = null;
            } elseif (!empty($parent['parent_id'])) {
                $parentId = (int)$parent['parent_id'];
            }
        }

        $id = $this->comments->create('knowledge_page', (int)$page['id'], (int)$this->user['id'], $body, $parentId);
        Response::json([
            'ok'      => true,
            'comment' => [
                'id'            => $id,
                'body_html'     => Markdown::render($body),
                'author_name'   => $this->user['name'],
                'author_avatar' => $this->user['avatar'] ?? null,
                'user_id'       => (int)$this->user['id'],
                'parent_id'     => $parentId,
                'created_at'    => iso_now_utc(),
                'can_delete'    => true,
            ],
        ]);
    }

    public function commentDelete(Request $req, array $params): void
    {
        $cid = (int)($params['cid'] ?? 0);
        $c   = $this->comments->findById($cid);
        if (!$c || (string)$c['entity_type'] !== 'knowledge_page') {
            Response::json(['error' => 'Not found'], 404); return;
        }
        $isAuthor = (int)$c['user_id'] === (int)$this->user['id'];
        $isAdmin  = ($this->user['role'] ?? '') === 'admin';
        if (!$isAuthor && !$isAdmin) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }
        $this->comments->delete($cid);
        Response::json(['ok' => true]);
    }

    // ─── Categories admin ────────────────────────────────────────────

    public function categoriesIndex(Request $req, array $params = []): void
    {
        AuthGuard::requireAdmin($this->user);
        $csrfToken = $this->csrfToken();
        $cats = $this->categories->listAll();

        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'knowledge', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => t('knowledge.categories.title'),
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => t('knowledge.categories.title'),
            'csrfToken' => $csrfToken,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('knowledge/categories', [
                'categories' => $cats,
                'csrfToken'  => $csrfToken,
                'success'    => $this->consumeFlash('flash_success'),
                'error'      => $this->consumeFlash('flash_error'),
            ]),
        ]));
    }

    public function categoryCreate(Request $req, array $params = []): void
    {
        AuthGuard::requireAdmin($this->user);
        $name = trim((string)($req->post['name'] ?? ''));
        if ($name === '') {
            $this->flash('flash_error', t('knowledge.error.category_name_required'));
        } else {
            try {
                $this->categories->create($name);
                $this->flash('flash_success', t('knowledge.flash.category_created'));
            } catch (\InvalidArgumentException $e) {
                $this->flash('flash_error', $e->getMessage());
            }
        }
        Response::redirect('/admin/knowledge/categories');
    }

    public function categoryRename(Request $req, array $params): void
    {
        AuthGuard::requireAdmin($this->user);
        $id = (int)($params['id'] ?? 0);
        $name = trim((string)($req->post['name'] ?? ''));
        try {
            $this->categories->rename($id, $name);
            $this->flash('flash_success', t('knowledge.flash.category_renamed'));
        } catch (\InvalidArgumentException $e) {
            $this->flash('flash_error', $e->getMessage());
        }
        Response::redirect('/admin/knowledge/categories');
    }

    public function categoryDelete(Request $req, array $params): void
    {
        AuthGuard::requireAdmin($this->user);
        $id = (int)($params['id'] ?? 0);
        $this->categories->delete($id);
        $this->flash('flash_success', t('knowledge.flash.category_deleted'));
        Response::redirect('/admin/knowledge/categories');
    }
}
