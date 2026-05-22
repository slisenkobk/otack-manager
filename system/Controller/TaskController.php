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
        Response::json(['ok' => true]);
    }
}
