<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\AttachmentRepository;
use App\Repository\CommentRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\EventBus;
use App\View\Renderer;
use PDO;

final class ProjectController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private TaskColumnRepository $columns,
        private TaskRepository $tasks,
        private TagRepository $tags,
        private UserRepository $users,
        private CommentRepository $comments,
        private AttachmentRepository $attachments,
        private FormSubmissionRepository $formSubmissions,
        private ActivityLogRepository $activity,
        private EventBus $events,
        private PDO $db,
    ) {
        parent::__construct($view, $user);
    }


    private function assertAccess(array $project): void {
        $isAdmin = $this->user['role'] === 'admin';
        if (!$isAdmin && !$this->members->isMember((int)$project['id'], (int)$this->user['id'])) {
            Response::forbidden('Not a project member'); exit;
        }
    }

    public function index(Request $req, array $params = []): void {
        $isAdmin = $this->user['role'] === 'admin';
        $allStatuses = array_keys(\project_statuses());
        $reqStatus   = (string)($req->query['status'] ?? 'active');
        $status  = in_array($reqStatus, $allStatuses, true) ? $reqStatus : 'active';
        $query   = trim((string)($req->query['q'] ?? ''));
        $page    = max(1, (int)($req->query['page'] ?? 1));
        $perPage = 12;
        $offset  = ($page - 1) * $perPage;
        $list    = $this->projects->listForUserPaged((int)$this->user['id'], $isAdmin, $status, $perPage, $offset, $query);
        $taskCounts = $this->tasks->countByProject(array_map(fn($p) => (int)$p['id'], $list));
        $statusCounts = [];
        foreach ($allStatuses as $s) {
            $statusCounts[$s] = $this->projects->countAllForUser((int)$this->user['id'], $isAdmin, $s, $query);
        }
        $total   = $statusCounts[$status] ?? 0;
        $pages   = max(1, (int)ceil($total / $perPage));
        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => t('nav.projects')]);
        Response::html($this->view->render('layouts/main', [
            'title' => t('nav.projects'), 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/index', [
                'projects'      => $list,
                'status'        => $status,
                'page'          => $page,
                'pages'         => $pages,
                'total'         => $total,
                'statusCounts'  => $statusCounts,
                'taskCounts'    => $taskCounts,
                'query'         => $query,
                'canCreateProject' => \App\Service\RolePolicy::canCreateProject($this->user),
            ]),
        ]));
    }

    public function create(Request $req, array $params = []): void {
        if (!\App\Service\RolePolicy::canCreateProject($this->user)) {
            if ($this->isJsonRequest($req)) { Response::json(['error' => 'Forbidden'], 403); return; }
            Response::forbidden('Employees cannot create projects'); return;
        }
        $isJson = $this->isJsonRequest($req);
        $data   = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $req->post;
        $name        = trim((string)($data['name'] ?? ''));
        $description = \App\Service\HtmlSanitizer::clean(trim((string)($data['description'] ?? '')));
        if ($name === '') {
            if ($isJson) { Response::json(['error' => 'Name is required'], 422); return; }
            Response::redirect('/projects'); return;
        }
        // ProjectRepository::create already wraps its INSERT in a transaction.
        // We run member add and column seed after, each atomic on its own.
        $color = $data['color'] ?? null;
        $id = $this->projects->create($name, $description ?: null, (int)$this->user['id'], $color);
        $this->members->add($id, (int)$this->user['id'], 'owner');
        $this->columns->seedDefaults($id);
        $this->events->fire('project.created', [
            'project_id' => $id,
            'name'       => $name,
            'actor_name' => $this->user['name'],
            'url'        => \abs_url('/projects/' . $id),
        ]);
        if ($isJson) { Response::json(['ok' => true, 'id' => $id]); return; }
        Response::redirect('/projects/' . $id . '?tab=overview');
    }

    public function show(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $this->assertAccess($project);
        $columns    = $this->columns->listForProject($id);
        $members    = $this->members->list($id);
        $tasksByCol = $this->tasks->listForProject($id);
        $taskTags   = $this->tags->listForProjectTasks($id);
        $taskMeta   = $this->tasks->countMetaForProject($id);
        $allTaskTagsInProject = [];
        foreach ($taskTags as $tags) {
            foreach ($tags as $t) {
                $allTaskTagsInProject[$t['name']] = $t;
            }
        }
        $allTaskTagsInProject = array_values($allTaskTagsInProject);
        $allUsersRaw = $this->users->listAll();
        $allUsers = array_values(array_filter($allUsersRaw, fn($u) => $u['status'] === 'approved'));
        $canEdit        = ($this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']));
        $isAdmin        = $this->user['role'] === 'admin';
        $currentUserId  = (int)$this->user['id'];
        $canPost        = $isAdmin || $this->members->isMember($id, $currentUserId);
        $projectComments     = $this->comments->listFor('project', $id);
        $projectAttachments  = $this->attachments->listFor('project', $id);
        $commentIds = array_column($projectComments, 'id');
        $commentAttachments = [];
        if ($commentIds) {
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM attachments WHERE entity_type = 'comment' AND entity_id IN ($placeholders) ORDER BY created_at ASC"
            );
            $stmt->execute($commentIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                $commentAttachments[(int)$a['entity_id']][] = $a;
            }
        }
        $projectTags         = $this->tags->listForProject($id);
        $allProjectTags      = $this->tags->listAll();
        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $statusPill = sprintf(
            '<span class="status status--%s">%s</span>',
            htmlspecialchars((string)$project['status'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars(\project_status_label($project['status']), ENT_QUOTES, 'UTF-8')
        );
        $topbar  = $this->view->render('partials/topbar', [
            'user'        => $this->user,
            'crumb'       => $project['name'],
            'crumbNum'    => str_pad((string)$project['id'], 2, '0', STR_PAD_LEFT),
            'crumbExtra'  => $statusPill,
            'csrfToken'   => $csrf,
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => $project['name'], 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/show', [
                'project'        => $project,
                'columns'        => $columns,
                'members'        => $members,
                'csrfToken'      => $csrf,
                'tab'            => $req->query['tab'] ?? 'board',
                'tasksByCol'     => $tasksByCol,
                'taskTags'       => $taskTags,
                'taskMeta'       => $taskMeta,
                'allTaskTagsInProject' => $allTaskTagsInProject,
                'allUsers'       => $allUsers,
                'canEdit'        => $canEdit,
                'isAdmin'        => $isAdmin,
                'currentUserId'  => $currentUserId,
                'canPost'        => $canPost,
                'projectComments'     => $projectComments,
                'projectAttachments' => $projectAttachments,
                'commentAttachments' => $commentAttachments,
                'projectTags'        => $projectTags,
                'allProjectTags'     => $allProjectTags,
            ]),
        ]));
    }

    public function editForm(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { Response::forbidden(); return; }
        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => 'Edit ' . $project['name']]);
        Response::html($this->view->render('layouts/main', [
            'title' => 'Edit ' . $project['name'], 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/form', ['csrfToken' => $csrf, 'project' => $project, 'mode' => 'edit']),
        ]));
    }

    public function update(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) {
            if ($this->isJsonRequest($req)) { Response::json(['error' => 'Forbidden'], 403); return; }
            Response::forbidden(); return;
        }
        $isJson = $this->isJsonRequest($req);
        $data   = $isJson ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $req->post;
        $fields = [];
        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name !== '') { $fields['name'] = $name; }
        }
        if (array_key_exists('description', $data)) {
            $rawDesc = (string)$data['description'];
            $fields['description'] = trim($rawDesc) === '' ? null : \App\Service\HtmlSanitizer::clean($rawDesc);
        }
        if (isset($data['status'])) {
            $status = trim((string)$data['status']);
            if (in_array($status, array_keys(\project_statuses()), true)) {
                $fields['status'] = $status;
            }
        }
        if (isset($data['color'])) {
            $color = (string)$data['color'];
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $fields['color'] = $color;
            }
        }
        if ($fields) {
            $this->projects->update($id, $fields);
        }
        $fresh   = $this->projects->findById($id);
        $name    = $fields['name'] ?? $project['name'];
        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $this->events->fire('project.updated', [
            'project_id' => $id,
            'name'       => $name !== '' ? $name : $project['name'],
            'actor_name' => $this->user['name'],
            'url'        => \abs_url('/projects/' . $id),
        ]);
        if ($isJson) {
            Response::json([
                'ok' => true,
                'project' => [
                    'id'          => (int)$fresh['id'],
                    'name'        => $fresh['name'],
                    'description' => \App\Service\LinkPreview::enhance((string)($fresh['description'] ?? '')),
                    'status'      => $fresh['status'],
                    'color'       => $fresh['color'] ?? '#1A1612',
                ],
            ]);
            return;
        }
        Response::redirect('/projects/' . $id);
    }

    public function togglePin(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::json(['error' => 'Not found'], 404); return; }
        $isAdmin = $this->user['role'] === 'admin';
        if (!$isAdmin && !$this->members->isMember($id, (int)$this->user['id'])) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }
        $shouldPin = empty($project['pinned_at']);
        $this->projects->setPinned($id, $shouldPin);
        Response::json(['ok' => true, 'pinned' => $shouldPin]);
    }

    private function isJsonRequest(Request $req): bool {
        $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        return stripos($ct, 'application/json') !== false;
    }

    public function delete(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { Response::json(['error' => 'Forbidden'], 403); return; }
        $this->projects->delete($id);
        // Detach any submissions converted into this project so they can be
        // re-promoted instead of pointing at a dead /projects/{id} link.
        $this->formSubmissions->detachConverted('project', $id);
        $this->events->fire('project.deleted', [
            'project_id' => $id,
            'name'       => $project['name'],
            'actor_name' => $this->user['name'],
        ]);
        $this->activity->log(
            'project.deleted',
            (int)$this->user['id'],
            null,
            null,
            "deleted project '" . $project['name'] . "'",
            ['project_id' => $id]
        );
        Response::json(['ok' => true]);
    }

    public function addMember(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        $project = $this->projects->findById($projectId);
        if (!$project) { Response::json(['error' => 'Not found'], 404); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($projectId, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { Response::json(['error' => 'Forbidden'], 403); return; }

        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $userId = (int)($data['user_id'] ?? 0);
        if (!$userId) { Response::json(['error' => 'user_id required'], 422); return; }
        $u = $this->users->findById($userId);
        if (!$u || $u['status'] !== 'approved') {
            Response::json(['error' => 'User must be approved'], 422); return;
        }
        $this->members->add($projectId, $userId);
        Response::json(['ok' => true]);
    }

    public function removeMember(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        $userId = (int)$params['userId'];
        $project = $this->projects->findById($projectId);
        if (!$project) { Response::json(['error' => 'Not found'], 404); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($projectId, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { Response::json(['error' => 'Forbidden'], 403); return; }

        // Refuse if removing the last owner
        if ($this->members->isOwner($projectId, $userId)) {
            $ownerCount = 0;
            foreach ($this->members->list($projectId) as $m) {
                if ($m['role'] === 'owner') $ownerCount++;
            }
            if ($ownerCount <= 1) {
                Response::json(['error' => 'Cannot remove the last owner'], 422); return;
            }
        }
        $this->members->remove($projectId, $userId);
        Response::json(['ok' => true]);
    }
}
