<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\FormSubmissionRepository;
use App\Repository\PollRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskLinkRepository;
use App\Repository\TaskRepository;

/**
 * Single source of the data writes a task/project mutation implies beyond
 * its own row: detaching sibling entities on delete and the kanban
 * `sub_status` badge on a column change.
 *
 * Shared by the web controllers and the REST API handlers so an API token
 * leaves the database in exactly the state the browser would. Counterpart
 * of DomainEvents, which owns the notification side effects — callers do
 * the write here first, then fire the matching DomainEvents method.
 * Permission checks stay with the callers (they differ per surface).
 */
final class EntityLifecycle
{
    public function __construct(
        private TaskRepository $tasks,
        private ProjectRepository $projects,
        private TaskColumnRepository $columns,
        private TaskLinkRepository $taskLinks,
        private FormSubmissionRepository $formSubmissions,
        private PollRepository $polls,
    ) {}

    /**
     * Delete a task and detach everything that pointed at it, so a form
     * submission or poll doesn't carry a dangling converted/summary task id
     * (the task page would 404 and "open linked entity" buttons would
     * mislead; the submission goes back to `new` and can be re-promoted).
     */
    public function deleteTask(int $id): void
    {
        $this->tasks->delete($id);
        $this->formSubmissions->detachConverted('task', $id);
        $this->polls->detachSummaryTask($id);
        $this->taskLinks->deleteForTask($id);
    }

    /**
     * Delete a project and detach submissions converted into it so they can
     * be re-promoted instead of pointing at a dead /projects/{id} link.
     *
     * The FK cascade removes the project's tasks, but not what points at
     * them from outside (submissions, poll summaries, links), so each task
     * goes through deleteTask() first. Comments and attachments are
     * polymorphic (entity_type/entity_id, no FK) and are left as they were.
     */
    public function deleteProject(int $id): void
    {
        foreach ($this->tasks->listForProject($id) as $columnTasks) {
            foreach ($columnTasks as $t) $this->deleteTask((int)$t['id']);
        }
        $this->projects->delete($id);
        $this->formSubmissions->detachConverted('project', $id);
    }

    /**
     * Move a task to a column/position, applying the sub_status rule.
     * Returns the target column row, or null (nothing written) when the
     * column does not belong to the task's project.
     *
     * @param array $task the task row as read before the move
     */
    public function moveTask(array $task, int $columnId, float $position): ?array
    {
        $cols = $this->columns->listForProject((int)$task['project_id']);
        $byId = [];
        foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
        if (!isset($byId[$columnId])) return null;

        $change = self::subStatusChange($task, $cols, $columnId);
        $subStatus = $change['sub_status'] ?? null;
        // Mirrors the pre-refactor web move: only clear when there is
        // something to clear.
        $clear = array_key_exists('sub_status', $change) && $subStatus === null && !empty($task['sub_status']);
        $this->tasks->move((int)$task['id'], $columnId, $position, $subStatus, $clear);
        return $byId[$columnId];
    }

    /**
     * The kanban sub_status rule for putting $task into $newColumnId.
     * "To Do" is the first non-backlog column by position.
     *
     *  - into To Do from Done             → 'reopened'
     *  - into To Do from any other board column → 'returned'
     *  - into To Do from Backlog (or To Do) → unchanged
     *  - into any other column            → cleared (null)
     *
     * Returns `['sub_status' => ?string]` when the field must be written, or
     * `[]` when it stays as is — ready to merge into a TaskRepository::update
     * field set. Callers decide whether a same-column write applies (the
     * PATCH paths skip the rule when column_id is unchanged; move does not).
     *
     * @param list<array> $cols the project's columns
     * @return array{sub_status?: ?string}
     */
    public static function subStatusChange(array $task, array $cols, int $newColumnId): array
    {
        $byId = [];
        foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
        $boardCols = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
        usort($boardCols, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
        $todoColId = $boardCols ? (int)$boardCols[0]['id'] : 0;
        $oldCol = $byId[(int)$task['column_id']] ?? null;

        if ($newColumnId !== $todoColId) return ['sub_status' => null];
        if (!$oldCol || (int)$oldCol['id'] === $todoColId) return [];
        if ((int)($oldCol['is_done'] ?? 0) === 1) return ['sub_status' => 'reopened'];
        if ((int)($oldCol['is_backlog'] ?? 0) === 1) return [];
        return ['sub_status' => 'returned'];
    }
}
