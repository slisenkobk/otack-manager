<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TaskRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMemberRepository;

final class TaskController extends BaseController {
    private TaskRepository $tasks;
    private ProjectRepository $projects;
    private ProjectMemberRepository $members;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->tasks = App::make('tasks');
        $this->projects = App::make('projects');
        $this->members = App::make('members');
    }

    private function assertMember(int $projectId): void {
        if ($this->user['role'] === 'admin') return;
        if (!$this->members->isMember($projectId, (int)$this->user['id'])) {
            Response::json(['error' => 'Forbidden'], 403); exit;
        }
    }

    public function create(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        if (!$this->projects->findById($projectId)) {
            Response::json(['error' => 'Project not found'], 404); return;
        }
        $this->assertMember($projectId);
        $data = json_decode(file_get_contents('php://input'), true) ?: $req->post;
        $columnId = (int)($data['column_id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$columnId || $title === '') {
            Response::json(['error' => 'column_id and title required'], 422); return;
        }
        $id = $this->tasks->create($projectId, $columnId, $title, (int)$this->user['id']);
        $task = $this->tasks->findById($id);
        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        $project = $this->projects->findById($projectId);
        App::make('events')->fire('task.created', [
            'task_id'      => $id,
            'title'        => $task['title'],
            'project_name' => $project['name'],
            'actor_name'   => $this->user['name'],
            'url'          => $baseUrl . '/tasks/' . $id,
        ]);
        Response::json(['ok' => true, 'task' => [
            'id' => (int)$task['id'],
            'title' => $task['title'],
            'column_id' => (int)$task['column_id'],
            'position' => (float)$task['position'],
        ]]);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        $this->tasks->delete($id);
        Response::json(['ok' => true]);
    }

    public function move(Request $req, array $params): void {
        $taskId = (int)$params['id'];
        $task = $this->tasks->findById($taskId);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $columnId = (int)($data['column_id'] ?? 0);
        $position = (float)($data['position'] ?? 0);
        if (!$columnId) { Response::json(['error' => 'column_id required'], 422); return; }
        $this->tasks->move($taskId, $columnId, $position);
        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        $cols = App::make('columns')->listForProject((int)$task['project_id']);
        $newColName = '';
        foreach ($cols as $c) if ((int)$c['id'] === $columnId) { $newColName = $c['name']; break; }
        App::make('events')->fire('task.status_changed', [
            'task_id'    => $taskId,
            'title'      => $task['title'],
            'new_column' => $newColName,
            'actor_name' => $this->user['name'],
            'url'        => $baseUrl . '/tasks/' . $taskId,
        ]);
        Response::json(['ok' => true]);
    }

    public function show(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::notFound(); return; }
        $projectId = (int)$task['project_id'];
        $project = $this->projects->findById($projectId);
        $isAdmin = $this->user['role'] === 'admin';
        if (!$isAdmin && !$this->members->isMember($projectId, (int)$this->user['id'])) {
            Response::forbidden(); return;
        }
        $columns = App::make('columns')->listForProject($projectId);
        $members = $this->members->list($projectId);
        $comments = App::make('comments')->listFor('task', $id);
        $attachments = App::make('attachments')->listFor('task', $id);
        $commentIds = array_column($comments, 'id');
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
        $taskTags    = App::make('tags')->listForTask($id);
        $allTaskTags = App::make('tags')->listForScope('task');
        $createdBy = App::make('users')->findById((int)$task['created_by']);
        $csrfToken = App::make('csrf')->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $project['name'] . ' / Task #' . $id,
        ]);
        Response::html($this->view->render('layouts/main', [
            'title' => $task['title'] . ' — ' . $project['name'],
            'csrfToken' => $csrfToken,
            'sidebar' => $sidebar,
            'topbar' => $topbar,
            'content' => $this->view->render('tasks/show', [
                'task' => $task, 'project' => $project, 'columns' => $columns,
                'members' => $members, 'comments' => $comments, 'attachments' => $attachments,
                'createdBy' => $createdBy, 'csrfToken' => $csrfToken,
                'currentUserId' => (int)$this->user['id'], 'isAdmin' => $isAdmin,
                'canEdit' => true,
                'taskTags'    => $taskTags,
                'allTaskTags' => $allTaskTags,
                'commentAttachments' => $commentAttachments,
            ]),
        ]));
    }

    public function search(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        if (!$this->projects->findById($projectId)) {
            Response::json(['error' => 'Not found'], 404); return;
        }
        $this->assertMember($projectId);
        $q   = trim($req->query['q'] ?? '');
        $tag = trim($req->query['tag'] ?? '');
        $db  = App::make('db');

        if ($q !== '' || $tag !== '') {
            if ($tag !== '') {
                $sql = "SELECT DISTINCT t.id FROM tasks t
                        INNER JOIN task_tag_map m ON m.task_id = t.id
                        INNER JOIN tags g ON g.id = m.tag_id
                        WHERE t.project_id = ? AND g.name = ?"
                      . ($q !== '' ? " AND t.title LIKE ?" : "")
                      . " LIMIT 200";
                $p = [$projectId, $tag];
                if ($q !== '') $p[] = '%' . $q . '%';
                $stmt = $db->prepare($sql);
                $stmt->execute($p);
            } else {
                $stmt = $db->prepare(
                    "SELECT id FROM tasks WHERE project_id = ? AND title LIKE ? LIMIT 200"
                );
                $stmt->execute([$projectId, '%' . $q . '%']);
            }
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        } else {
            $ids = null; // null = no filter
        }
        Response::json(['ok' => true, 'ids' => $ids]);
    }

    public function update(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $fields = [];
        if (isset($data['title'])) {
            $title = trim((string)$data['title']);
            if ($title === '') { Response::json(['error' => 'Title cannot be empty'], 422); return; }
            $fields['title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $rawDesc = $data['description'] === '' ? '' : (string)$data['description'];
            $fields['description'] = $rawDesc === '' ? null : \App\Service\HtmlSanitizer::clean($rawDesc);
        }
        if (isset($data['column_id'])) {
            $fields['column_id'] = (int)$data['column_id'];
        }
        if (array_key_exists('assignee_id', $data)) {
            $aid = $data['assignee_id'];
            $fields['assignee_id'] = $aid === null || $aid === '' ? null : (int)$aid;
        }
        if (array_key_exists('due_date', $data)) {
            $fields['due_date'] = $data['due_date'] === '' ? null : $data['due_date'];
        }

        $this->tasks->update($id, $fields);
        $fresh = $this->tasks->findById($id);
        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        if (isset($fields['column_id']) && (int)$fields['column_id'] !== (int)$task['column_id']) {
            $cols = App::make('columns')->listForProject((int)$task['project_id']);
            $newColName = '';
            foreach ($cols as $c) if ((int)$c['id'] === (int)$fields['column_id']) { $newColName = $c['name']; break; }
            App::make('events')->fire('task.status_changed', [
                'task_id'    => $id,
                'title'      => $fresh['title'],
                'new_column' => $newColName,
                'actor_name' => $this->user['name'],
                'url'        => $baseUrl . '/tasks/' . $id,
            ]);
        }
        if (array_key_exists('assignee_id', $fields) && (int)($fields['assignee_id'] ?? 0) !== (int)($task['assignee_id'] ?? 0)) {
            $assigneeName = 'Unassigned';
            if ($fields['assignee_id']) {
                $assignee = App::make('users')->findById((int)$fields['assignee_id']);
                if ($assignee) $assigneeName = $assignee['name'];
            }
            App::make('events')->fire('task.assignee_changed', [
                'task_id'       => $id,
                'title'         => $fresh['title'],
                'assignee_name' => $assigneeName,
                'actor_name'    => $this->user['name'],
                'url'           => $baseUrl . '/tasks/' . $id,
            ]);
        }
        // Description is now stored as sanitised HTML from Quill — return it directly
        $descHtml = (string)($fresh['description'] ?? '');
        Response::json([
            'ok' => true,
            'task' => [
                'id' => (int)$fresh['id'],
                'title' => $fresh['title'],
                'description' => $fresh['description'],
                'description_html' => $descHtml,
                'column_id' => (int)$fresh['column_id'],
                'assignee_id' => $fresh['assignee_id'] ? (int)$fresh['assignee_id'] : null,
                'due_date' => $fresh['due_date'],
            ],
        ]);
    }
}
