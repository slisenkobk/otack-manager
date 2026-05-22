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
            http_response_code(403);
            echo '<h1>403</h1><p>Not a project member</p>';
            exit;
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
        $csrf    = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', ['user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrf]);
        $topbar  = $this->view->render('partials/topbar', ['user' => $this->user, 'crumb' => $project['name']]);
        Response::html($this->view->render('layouts/main', [
            'title' => $project['name'], 'csrfToken' => $csrf, 'sidebar' => $sidebar, 'topbar' => $topbar,
            'content' => $this->view->render('projects/show', [
                'project' => $project, 'columns' => $columns, 'members' => $members, 'csrfToken' => $csrf,
                'tab' => $req->query['tab'] ?? 'board',
                'tasksByCol' => $tasksByCol,
            ]),
        ]));
    }

    public function editForm(Request $req, array $params): void {
        $id      = (int)$params['id'];
        $project = $this->projects->findById($id);
        if (!$project) { Response::notFound(); return; }
        $isOwnerOrAdmin = $this->user['role'] === 'admin' || $this->members->isOwner($id, (int)$this->user['id']);
        if (!$isOwnerOrAdmin) { http_response_code(403); echo '<h1>403</h1>'; return; }
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
        if (!$isOwnerOrAdmin) { http_response_code(403); echo '<h1>403</h1>'; return; }
        $name        = trim($req->post['name'] ?? '');
        $description = trim($req->post['description'] ?? '');
        if ($name !== '') {
            $this->projects->update($id, ['name' => $name, 'description' => $description ?: null]);
        }
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
}
