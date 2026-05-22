<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\TaskColumnRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMemberRepository;

final class ColumnController extends BaseController {
    private TaskColumnRepository $cols;
    private ProjectRepository $projects;
    private ProjectMemberRepository $members;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->cols = App::make('columns');
        $this->projects = App::make('projects');
        $this->members = App::make('members');
    }

    private function assertMember(int $projectId): void {
        if ($this->user['role'] === 'admin') return;
        if (!$this->members->isMember($projectId, (int)$this->user['id'])) {
            Response::json(['error' => 'Forbidden'], 403); exit;
        }
    }

    public function create(Request $req, array $params = []): void {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $projectId = (int)($data['project_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $color = (string)($data['color'] ?? '#8B7C68');
        if (!$projectId || $name === '') {
            Response::json(['error' => 'project_id and name required'], 422); return;
        }
        if (!$this->projects->findById($projectId)) {
            Response::json(['error' => 'Not found'], 404); return;
        }
        $this->assertMember($projectId);
        $id = $this->cols->create($projectId, $name, $color);
        Response::json(['ok' => true, 'column' => ['id' => $id, 'name' => $name, 'color' => $color]]);
    }

    public function update(Request $req, array $params): void {
        $id = (int)$params['id'];
        $stmt = App::make('db')->prepare('SELECT * FROM task_columns WHERE id = ?');
        $stmt->execute([$id]);
        $col = $stmt->fetch();
        if (!$col) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$col['project_id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $fields = [];
        if (isset($data['name'])) $fields['name'] = trim((string)$data['name']);
        if (isset($data['color'])) $fields['color'] = (string)$data['color'];
        if (isset($data['position'])) $fields['position'] = (int)$data['position'];
        $this->cols->update($id, $fields);
        Response::json(['ok' => true]);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        $stmt = App::make('db')->prepare('SELECT * FROM task_columns WHERE id = ?');
        $stmt->execute([$id]);
        $col = $stmt->fetch();
        if (!$col) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$col['project_id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $moveTo = isset($data['move_to']) && $data['move_to'] !== '' ? (int)$data['move_to'] : null;

        $t = App::make('db')->prepare('SELECT COUNT(*) AS c FROM tasks WHERE column_id = ?');
        $t->execute([$id]);
        $hasTasks = (int)$t->fetch()['c'] > 0;
        if ($hasTasks && $moveTo === null) {
            Response::json(['error' => 'Column has tasks. Provide move_to.', 'has_tasks' => true], 422); return;
        }
        try {
            $this->cols->delete($id, $moveTo);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 500); return;
        }
        Response::json(['ok' => true]);
    }
}
