<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\TagRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\TaskRepository;
use App\View\Renderer;

final class TagController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
        private TagRepository $tags,
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private TaskRepository $tasksRepo,
    ) {
        parent::__construct($view, $user);
    }

    private function assertProjectMember(int $projectId): void {
        if ($this->user['role'] === 'admin') return;
        if (!$this->members->isMember($projectId, (int)$this->user['id'])) {
            Response::json(['error' => 'Forbidden'], 403); exit;
        }
    }

    public function create(Request $req, array $params = []): void {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $scope = $data['scope'] ?? '';
        $name  = trim((string)($data['name'] ?? ''));
        if (!in_array($scope, ['project', 'task'], true) || $name === '') {
            Response::json(['error' => 'scope and name required'], 422); return;
        }
        $id  = $this->tags->create($scope, $name);
        $tag = $this->tags->findById($id);
        Response::json(['ok' => true, 'tag' => $tag]);
    }

    public function attachToProject(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        if (!$this->projects->findById($projectId)) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertProjectMember($projectId);
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $tagId = (int)($data['tag_id'] ?? 0);
        if (!$tagId) { Response::json(['error' => 'tag_id required'], 422); return; }
        $this->tags->attachToProject($projectId, $tagId);
        Response::json(['ok' => true]);
    }

    public function detachFromProject(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        $tagId     = (int)$params['tagId'];
        if (!$this->projects->findById($projectId)) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertProjectMember($projectId);
        $this->tags->detachFromProject($projectId, $tagId);
        Response::json(['ok' => true]);
    }

    public function attachToTask(Request $req, array $params): void {
        $taskId = (int)$params['id'];
        $task   = $this->tasksRepo->findById($taskId);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertProjectMember((int)$task['project_id']);
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $tagId = (int)($data['tag_id'] ?? 0);
        if (!$tagId) { Response::json(['error' => 'tag_id required'], 422); return; }
        $this->tags->attachToTask($taskId, $tagId);
        Response::json(['ok' => true]);
    }

    public function detachFromTask(Request $req, array $params): void {
        $taskId = (int)$params['id'];
        $tagId  = (int)$params['tagId'];
        $task   = $this->tasksRepo->findById($taskId);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertProjectMember((int)$task['project_id']);
        $this->tags->detachFromTask($taskId, $tagId);
        Response::json(['ok' => true]);
    }
}
