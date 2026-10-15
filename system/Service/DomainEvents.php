<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\ActivityLogRepository;
use App\Repository\CommentRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;

/**
 * Single source of the side effects a domain mutation produces: the EventBus
 * event (Telegram listeners hang off it) and the activity_log row.
 *
 * Shared by the web controllers and the REST API handlers so a comment posted
 * through an API token notifies exactly like one posted from the browser.
 * Callers persist the change first, then call the matching method once —
 * nothing below writes domain data, so there is no second path that could
 * fire the same event twice.
 *
 * `$actor` is the acting user row (session user on the web, the token's user
 * on the API); only `id` and `name` are read. Payload keys are the contract
 * TelegramListeners and NotificationLogger rely on — keep them stable.
 */
final class DomainEvents
{
    public function __construct(
        private EventBus $events,
        private ActivityLogRepository $activity,
        private ProjectRepository $projects,
        private TaskRepository $tasks,
        private UserRepository $users,
        private CommentRepository $comments,
    ) {}

    // ─── comments ───────────────────────────────────────────────────────────

    /** @param array $entity the project or task row the comment was posted on */
    public function commentCreated(array $actor, string $entityType, int $entityId, array $entity, int $commentId, string $body, ?string $replyToAuthor): void
    {
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
            'comment_id'     => $commentId,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'entity_label'   => $entityType,
            'target_name'    => $targetName,
            'author'         => $actor['name'],
            'body_text'      => $body,
            'url'            => $entityUrl,
            'reply_to_author'=> $replyToAuthor,
        ]);

        $activityProjectId = $entityType === 'project' ? $entityId : (int)($entity['project_id'] ?? 0);
        $activityTaskId    = $entityType === 'task' ? $entityId : null;
        $this->activity->log([
            'event'      => 'comment.created',
            'actor_id'   => (int)$actor['id'],
            'project_id' => $activityProjectId ?: null,
            'task_id'    => $activityTaskId,
            'summary'    => mb_substr(strip_tags($body), 0, 200),
            'meta'       => ['comment_id' => $commentId],
        ]);
    }

    /** @param array $comment the deleted comment row (read before delete) */
    public function commentDeleted(array $actor, array $comment): void
    {
        $id         = (int)$comment['id'];
        $entityType = (string)$comment['entity_type'];
        $entityId   = (int)$comment['entity_id'];
        $targetName = '';
        $entityUrl  = '';
        $task       = null;
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
            'actor_name'   => $actor['name'],
            'url'          => $entityUrl,
        ]);
        $activityProjectId = null;
        $activityTaskId    = null;
        if ($entityType === 'project') {
            $activityProjectId = $entityId;
        } elseif ($entityType === 'task') {
            $activityTaskId = $entityId;
            if ($task) $activityProjectId = (int)$task['project_id'];
        }
        $this->activity->log([
            'event'      => 'comment.deleted',
            'actor_id'   => (int)$actor['id'],
            'project_id' => $activityProjectId,
            'task_id'    => $activityTaskId,
            'summary'    => "deleted a comment on {$entityType} '{$targetName}'",
            'meta'       => ['comment_id' => $id],
        ]);
    }

    // ─── tasks ──────────────────────────────────────────────────────────────

    public function taskCreated(array $actor, array $task, array $project, int $columnId): void
    {
        $id = (int)$task['id'];
        $this->events->fire('task.created', [
            'task_id'      => $id,
            'title'        => $task['title'],
            'project_name' => $project['name'],
            'actor_name'   => $actor['name'],
            'url'          => \abs_url('/tasks/' . $id),
        ]);
        $this->activity->log([
            'event'      => 'task.created',
            'actor_id'   => (int)$actor['id'],
            'project_id' => (int)$project['id'],
            'task_id'    => $id,
            'summary'    => "added task '" . $task['title'] . "'",
            'meta'       => ['column_id' => $columnId],
        ]);
    }

    /** @param array $task the deleted task row (read before delete) */
    public function taskDeleted(array $actor, array $task, ?array $project): void
    {
        $id = (int)$task['id'];
        $this->events->fire('task.deleted', [
            'task_id'      => $id,
            'title'        => $task['title'],
            'project_name' => $project['name'] ?? '',
            'actor_name'   => $actor['name'],
        ]);
        $this->activity->log([
            'event'      => 'task.deleted',
            'actor_id'   => (int)$actor['id'],
            'project_id' => (int)$task['project_id'],
            'summary'    => "deleted task '" . $task['title'] . "'",
            'meta'       => ['task_id' => $id],
        ]);
    }

    /** Column move — fired by the kanban move endpoint and by a column_id edit. */
    public function taskStatusChanged(array $actor, int $taskId, int $projectId, string $title, int $columnId, string $newColumnName): void
    {
        $this->events->fire('task.status_changed', [
            'task_id'    => $taskId,
            'title'      => $title,
            'new_column' => $newColumnName,
            'actor_name' => $actor['name'],
            'url'        => \abs_url('/tasks/' . $taskId),
        ]);
        $this->activity->log([
            'event'      => 'task.status_changed',
            'actor_id'   => (int)$actor['id'],
            'project_id' => $projectId,
            'task_id'    => $taskId,
            'summary'    => 'moved to ' . ($newColumnName !== '' ? $newColumnName : 'another column'),
            'meta'       => ['column_id' => $columnId, 'new_column' => $newColumnName],
        ]);
    }

    /** Event only — the web path has never written an activity row for this. */
    public function taskAssigneeChanged(array $actor, int $taskId, string $title, ?int $assigneeId): void
    {
        $assigneeName = 'Unassigned';
        if ($assigneeId) {
            $assignee = $this->users->findById($assigneeId);
            if ($assignee) $assigneeName = $assignee['name'];
        }
        $this->events->fire('task.assignee_changed', [
            'task_id'       => $taskId,
            'title'         => $title,
            'assignee_name' => $assigneeName,
            'actor_name'    => $actor['name'],
            'url'           => \abs_url('/tasks/' . $taskId),
        ]);
    }

    public function taskLinked(array $actor, array $task, array $otherTask, ?array $project): void
    {
        $id    = (int)$task['id'];
        $other = (int)$otherTask['id'];
        $this->events->fire('task.linked', [
            'task_id'        => $id,
            'task_title'     => $task['title'],
            'linked_task_id' => $other,
            'linked_title'   => $otherTask['title'],
            'project_name'   => $project['name'] ?? '',
            'actor_name'     => $actor['name'],
            'url'            => \abs_url('/tasks/' . $id),
        ]);
        $this->activity->log(
            'task.linked',
            (int)$actor['id'],
            (int)$task['project_id'],
            $id,
            "linked '" . $task['title'] . "' ↔ '" . $otherTask['title'] . "'",
            ['linked_task_id' => $other]
        );
    }

    /** @param ?array $otherTask null when the other side no longer exists */
    public function taskUnlinked(array $actor, array $task, int $otherId, ?array $otherTask, ?array $project): void
    {
        $id          = (int)$task['id'];
        $linkedTitle = $otherTask['title'] ?? ('#' . $otherId);
        $this->events->fire('task.unlinked', [
            'task_id'        => $id,
            'task_title'     => $task['title'],
            'linked_task_id' => $otherId,
            'linked_title'   => $linkedTitle,
            'project_name'   => $project['name'] ?? '',
            'actor_name'     => $actor['name'],
            'url'            => \abs_url('/tasks/' . $id),
        ]);
        $this->activity->log(
            'task.unlinked',
            (int)$actor['id'],
            (int)$task['project_id'],
            $id,
            "unlinked '" . $task['title'] . "' ↔ '" . $linkedTitle . "'",
            ['linked_task_id' => $otherId]
        );
    }

    // ─── projects ───────────────────────────────────────────────────────────

    /** Event only — plain project creation has never written an activity row. */
    public function projectCreated(array $actor, int $projectId, string $name): void
    {
        $this->events->fire('project.created', [
            'project_id' => $projectId,
            'name'       => $name,
            'actor_name' => $actor['name'],
            'url'        => \abs_url('/projects/' . $projectId),
        ]);
    }

    /** Task → project spin-off: the same event plus an activity row naming the source task. */
    public function projectPromotedFromTask(array $actor, int $projectId, string $name, array $task, int $copiedAttachments): void
    {
        $this->projectCreated($actor, $projectId, $name);
        $this->activity->log([
            'event'      => 'project.created',
            'actor_id'   => (int)$actor['id'],
            'project_id' => $projectId,
            'summary'    => "spun off project '$name' from task '" . $task['title'] . "'",
            'meta'       => ['from_task_id' => (int)$task['id'], 'copied_attachments' => $copiedAttachments],
        ]);
    }

    /** Event only — matches ProjectController::update. */
    public function projectUpdated(array $actor, int $projectId, string $name): void
    {
        $this->events->fire('project.updated', [
            'project_id' => $projectId,
            'name'       => $name,
            'actor_name' => $actor['name'],
            'url'        => \abs_url('/projects/' . $projectId),
        ]);
    }

    /** @param array $project the deleted project row (read before delete) */
    public function projectDeleted(array $actor, array $project): void
    {
        $id = (int)$project['id'];
        $this->events->fire('project.deleted', [
            'project_id' => $id,
            'name'       => $project['name'],
            'actor_name' => $actor['name'],
        ]);
        $this->activity->log([
            'event'    => 'project.deleted',
            'actor_id' => (int)$actor['id'],
            'summary'  => "deleted project '" . $project['name'] . "'",
            'meta'     => ['project_id' => $id],
        ]);
    }

    // ─── attachments ────────────────────────────────────────────────────────

    /**
     * Activity row only (no EventBus event exists for uploads). Resolves the
     * project/task the row should be filed under, following a comment
     * attachment through to the comment's own entity.
     */
    public function attachmentUploaded(array $actor, array $attachment, string $entityType, int $entityId): void
    {
        $activityProjectId = null; $activityTaskId = null;
        if ($entityType === 'project') {
            $activityProjectId = $entityId;
        } elseif ($entityType === 'task') {
            $activityTaskId = $entityId;
            $task = $this->tasks->findById($entityId);
            if ($task) $activityProjectId = (int)$task['project_id'];
        } elseif ($entityType === 'comment') {
            $c = $this->comments->findById($entityId);
            if ($c) {
                if ($c['entity_type'] === 'project') {
                    $activityProjectId = (int)$c['entity_id'];
                } elseif ($c['entity_type'] === 'task') {
                    $activityTaskId = (int)$c['entity_id'];
                    $task = $this->tasks->findById($activityTaskId);
                    if ($task) $activityProjectId = (int)$task['project_id'];
                }
            }
        }
        $this->activity->log(
            'attachment.uploaded',
            (int)$actor['id'],
            $activityProjectId,
            $activityTaskId,
            'uploaded ' . $attachment['original_name'],
            ['attachment_id' => (int)$attachment['id'], 'filename' => $attachment['original_name'], 'entity_type' => $entityType]
        );
    }
}
