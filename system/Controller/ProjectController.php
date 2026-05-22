<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;

final class ProjectController extends BaseController {
    private \App\Repository\ProjectRepository $projects;
    private \App\Repository\ProjectMemberRepository $members;
    private \App\Repository\TaskColumnRepository $columns;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->projects = App::make('projects');
        $this->members  = App::make('members');
        $this->columns  = App::make('columns');
    }

    private function csrfToken(): string { return App::make('csrf')->token(); }

    private function assertAccess(array $project): void {
        $isAdmin = $this->user['role'] === 'admin';
        if (!$isAdmin && !$this->members->isMember((int)$project['id'], (int)$this->user['id'])) {
            Response::forbidden('Not a project member'); exit;
        }
    }

    public function index(Request $req, array $params = []): void {
        $isAdmin = $this->user['role'] === 'admin';
        $list = $this->projects->listForUser((int)$this->user['id'], $isAdmin);
        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => 'Projects']);
        Response::html($this->view->render('layouts/main', [
            'title' => 'Projects', 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/index', ['projects' => $list]),
        ]));
    }

    public function createForm(Request $req, array $params = []): void {
        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => 'New project']);
        Response::html($this->view->render('layouts/main', [
            'title' => 'New project', 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/form', ['csrfToken' => $csrf, 'project' => null, 'mode' => 'create']),
        ]));
    }

    public function create(Request $req, array $params = []): void {
        $name        = trim($req->post['name'] ?? '');
        $description = trim($req->post['description'] ?? '');
        if ($name === '') { Response::redirect('/projects/new'); return; }
        // ProjectRepository::create already wraps its INSERT in a transaction.
        // We run member add and column seed after, each atomic on its own.
        $id = $this->projects->create($name, $description ?: null, (int)$this->user['id']);
        $this->members->add($id, (int)$this->user['id'], 'owner');
        $this->columns->seedDefaults($id);
        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        App::make('events')->fire('project.created', [
            'project_id' => $id,
            'name'       => $name,
            'actor_name' => $this->user['name'],
            'url'        => $baseUrl . '/projects/' . $id,
        ]);
        Response::redirect('/projects/' . $id);
    }

    public function show(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $this->assertAccess($project);
        $columns    = $this->columns->listForProject($id);
        $members    = $this->members->list($id);
        $tasksByCol = App::make('tasks')->listForProject($id);
        $allUsersRaw = App::make('users')->listAll();
        $allUsers = array_values(array_filter($allUsersRaw, fn($u) => $u['status'] === 'approved'));
        $canEdit        = ($this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']));
        $isAdmin        = $this->user['role'] === 'admin';
        $currentUserId  = (int)$this->user['id'];
        $canPost        = $isAdmin || $this->members->isMember($id, $currentUserId);
        $projectComments     = App::make('comments')->listFor('project', $id);
        $projectAttachments  = App::make('attachments')->listFor('project', $id);
        $commentIds = array_column($projectComments, 'id');
        $commentAttachments = [];
        if ($commentIds) {
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $stmt = App::make('db')->prepare(
                "SELECT * FROM attachments WHERE entity_type = 'comment' AND entity_id IN ($placeholders) ORDER BY created_at ASC"
            );
            $stmt->execute($commentIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                $commentAttachments[(int)$a['entity_id']][] = $a;
            }
        }
        $projectTags         = App::make('tags')->listForProject($id);
        $allProjectTags      = App::make('tags')->listForScope('project');
        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => $project['name']]);
        Response::html($this->view->render('layouts/main', [
            'title' => $project['name'], 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/show', [
                'project'        => $project,
                'columns'        => $columns,
                'members'        => $members,
                'csrfToken'      => $csrf,
                'tab'            => $req->query['tab'] ?? 'board',
                'tasksByCol'     => $tasksByCol,
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
        if (!$isOwnerOrAdmin) { Response::forbidden(); return; }
        $name        = trim($req->post['name'] ?? '');
        $description = trim($req->post['description'] ?? '');
        if ($name !== '') {
            $this->projects->update($id, ['name' => $name, 'description' => $description ?: null]);
        }
        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        App::make('events')->fire('project.updated', [
            'project_id' => $id,
            'name'       => $name !== '' ? $name : $project['name'],
            'actor_name' => $this->user['name'],
            'url'        => $baseUrl . '/projects/' . $id,
        ]);
        Response::redirect('/projects/' . $id);
    }

    public function delete(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { Response::json(['error' => 'Forbidden'], 403); return; }
        $this->projects->delete($id);
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
        $u = App::make('users')->findById($userId);
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
