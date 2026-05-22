<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Service\Markdown;
use App\Repository\CommentRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\TaskRepository;
use App\Repository\ProjectRepository;

final class CommentController extends BaseController
{
    private CommentRepository       $comments;
    private ProjectMemberRepository $members;
    private TaskRepository          $tasks;
    private ProjectRepository       $projects;

    public function __construct($view, $user = null)
    {
        parent::__construct($view, $user);
        $this->comments  = App::make('comments');
        $this->members   = App::make('members');
        $this->tasks     = App::make('tasks');
        $this->projects  = App::make('projects');
    }

    /** Assert the current user is a member (or admin) of the project/task entity. */
    private function assertMembership(string $entityType, int $entityId): array
    {
        if ($entityType === 'project') {
            $project = $this->projects->findById($entityId);
            if (!$project) {
                Response::json(['error' => 'Project not found'], 404);
                exit;
            }
            if ($this->user['role'] !== 'admin'
                && !$this->members->isMember($entityId, (int)$this->user['id'])
            ) {
                Response::json(['error' => 'Forbidden'], 403);
                exit;
            }
            return $project;
        }

        if ($entityType === 'task') {
            $task = $this->tasks->findById($entityId);
            if (!$task) {
                Response::json(['error' => 'Task not found'], 404);
                exit;
            }
            if ($this->user['role'] !== 'admin'
                && !$this->members->isMember((int)$task['project_id'], (int)$this->user['id'])
            ) {
                Response::json(['error' => 'Forbidden'], 403);
                exit;
            }
            return $task;
        }

        Response::json(['error' => 'Invalid entity_type'], 422);
        exit;
    }

    public function create(Request $req, array $params = []): void
    {
        $data       = json_decode(file_get_contents('php://input'), true) ?? [];
        $entityType = $data['entity_type'] ?? '';
        $entityId   = (int)($data['entity_id'] ?? 0);
        $body       = trim($data['body'] ?? '');

        if ($body === '' || !in_array($entityType, ['project', 'task'], true) || !$entityId) {
            Response::json(['error' => 'Invalid input'], 422);
            return;
        }

        $entity = $this->assertMembership($entityType, $entityId);
        $id = $this->comments->create($entityType, $entityId, (int)$this->user['id'], $body);

        $baseUrl = rtrim(App::env('APP_URL', ''), '/');
        $targetName = '';
        $entityUrl  = '';
        if ($entityType === 'project') {
            $targetName = $entity['name'];
            $entityUrl  = $baseUrl . '/projects/' . $entityId;
        } elseif ($entityType === 'task') {
            $targetName = $entity['title'];
            $entityUrl  = $baseUrl . '/tasks/' . $entityId;
        }
        App::make('events')->fire('comment.created', [
            'comment_id'   => $id,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_label' => $entityType,
            'target_name'  => $targetName,
            'author'       => $this->user['name'],
            'body_text'    => $body,
            'url'          => $entityUrl,
        ]);

        Response::json([
            'ok'      => true,
            'comment' => [
                'id'          => $id,
                'body_html'   => Markdown::render($body),
                'author_name' => $this->user['name'],
                'created_at'  => (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'can_delete'  => true,
            ],
        ]);
    }

    public function delete(Request $req, array $params): void
    {
        $id = (int)$params['id'];
        $c  = $this->comments->findById($id);
        if (!$c) {
            Response::json(['error' => 'Not found'], 404);
            return;
        }

        $isAdmin  = $this->user['role'] === 'admin';
        $isAuthor = (int)$c['user_id'] === (int)$this->user['id'];
        if (!$isAdmin && !$isAuthor) {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $this->comments->delete($id);
        Response::json(['ok' => true]);
    }
}
