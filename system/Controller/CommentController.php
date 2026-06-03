<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\CommentRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\EventBus;
use App\Service\Markdown;
use App\Service\RolePolicy;
use App\View\Renderer;

final class CommentController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private CommentRepository $comments,
        private ProjectMemberRepository $members,
        private TaskRepository $tasks,
        private ProjectRepository $projects,
        private UserRepository $users,
        private ActivityLogRepository $activity,
        private EventBus $events,
    ) {
        parent::__construct($view, $user);
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
        $data       = $req->jsonBody([]);
        $entityType = $data['entity_type'] ?? '';
        $entityId   = (int)($data['entity_id'] ?? 0);
        $body       = trim($data['body'] ?? '');
        $parentId   = isset($data['parent_id']) && $data['parent_id'] ? (int)$data['parent_id'] : null;

        if ($body === '' || !in_array($entityType, ['project', 'task'], true) || !$entityId) {
            Response::json(['error' => 'Invalid input'], 422);
            return;
        }

        $entity = $this->assertMembership($entityType, $entityId);

        // Validate parent_id: must belong to the same entity. Normalize to the top-level
        // root so threads stay one level deep (no replies-to-replies trees).
        $replyToAuthor = null;
        if ($parentId !== null) {
            $parent = $this->comments->findById($parentId);
            if (!$parent
                || (string)$parent['entity_type'] !== $entityType
                || (int)$parent['entity_id']   !== $entityId
            ) {
                Response::json(['error' => 'Invalid parent comment'], 422); return;
            }
            $parentId = (int)($parent['parent_id'] ?: $parent['id']);
            // Resolve the root's author for the notification message
            $rootAuthor = $this->users->findById((int)$parent['user_id']);
            $replyToAuthor = $rootAuthor['name'] ?? null;
        }

        $id = $this->comments->create($entityType, $entityId, (int)$this->user['id'], $body, $parentId);

        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $targetName = '';
        $entityUrl  = '';
        if ($entityType === 'project') {
            $targetName = $entity['name'];
            $entityUrl  = \abs_url('/projects/' . $entityId);
        } elseif ($entityType === 'task') {
            $targetName = $entity['title'];
            $entityUrl  = \abs_url('/tasks/' . $entityId);
        }
        $this->events->fire('comment.created', [
            'comment_id'     => $id,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'entity_label'   => $entityType,
            'target_name'    => $targetName,
            'author'         => $this->user['name'],
            'body_text'      => $body,
            'url'            => $entityUrl,
            'reply_to_author'=> $replyToAuthor,
        ]);

        $activityProjectId = $entityType === 'project' ? $entityId : (int)($entity['project_id'] ?? 0);
        $activityTaskId    = $entityType === 'task' ? $entityId : null;
        $this->activity->log([
            'event'      => 'comment.created',
            'actor_id'   => (int)$this->user['id'],
            'project_id' => $activityProjectId ?: null,
            'task_id'    => $activityTaskId,
            'summary'    => mb_substr(strip_tags($body), 0, 200),
            'meta'       => ['comment_id' => $id],
        ]);

        Response::json([
            'ok'      => true,
            'comment' => [
                'id'            => $id,
                'body_html'     => \App\Service\LinkPreview::enhance(Markdown::render($body)),
                'author_name'   => $this->user['name'],
                'author_avatar' => $this->user['avatar'] ?? null,
                'user_id'       => (int)$this->user['id'],
                'parent_id'     => $parentId,
                'created_at'    => (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                'can_delete'    => true,
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

        if (!RolePolicy::canDeleteComment($this->user, $c)) {
            Response::json(['error' => 'Forbidden'], 403);
            return;
        }

        $this->comments->delete($id);

        $entityType = (string)$c['entity_type'];
        $entityId   = (int)$c['entity_id'];
        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $targetName = '';
        $entityUrl  = '';
        if ($entityType === 'project') {
            $proj = $this->projects->findById($entityId);
            $targetName = $proj['name'] ?? ('#' . $entityId);
            $entityUrl  = \abs_url('/projects/' . $entityId);
        } elseif ($entityType === 'task') {
            $task = $this->tasks->findById($entityId);
            $targetName = $task['title'] ?? ('#' . $entityId);
            $entityUrl  = \abs_url('/tasks/' . $entityId);
        }
        $this->events->fire('comment.deleted', [
            'comment_id'   => $id,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'entity_label' => $entityType,
            'target_name'  => $targetName,
            'actor_name'   => $this->user['name'],
            'url'          => $entityUrl,
        ]);
        $activityProjectId = null;
        $activityTaskId    = null;
        if ($entityType === 'project') {
            $activityProjectId = $entityId;
        } elseif ($entityType === 'task') {
            $activityTaskId = $entityId;
            $task = $this->tasks->findById($entityId);
            if ($task) $activityProjectId = (int)$task['project_id'];
        }
        $this->activity->log([
            'event'      => 'comment.deleted',
            'actor_id'   => (int)$this->user['id'],
            'project_id' => $activityProjectId,
            'task_id'    => $activityTaskId,
            'summary'    => "deleted a comment on {$entityType} '{$targetName}'",
            'meta'       => ['comment_id' => $id],
        ]);
        Response::json(['ok' => true]);
    }
}
