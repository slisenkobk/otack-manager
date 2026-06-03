<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\AttachmentRepository;
use App\Repository\CommentRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\PollRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\TagRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskLinkRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\EventBus;
use App\View\Renderer;
use PDO;

final class TaskController extends BaseController {
    public function __construct(
        Renderer $view,
        ?array $user,
        private TaskRepository $tasks,
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private TaskColumnRepository $columns,
        private TaskLinkRepository $taskLinks,
        private TagRepository $tags,
        private UserRepository $users,
        private CommentRepository $comments,
        private AttachmentRepository $attachments,
        private FormSubmissionRepository $formSubmissions,
        private PollRepository $polls,
        private ActivityLogRepository $activity,
        private EventBus $events,
        private Csrf $csrf,
        private PDO $db,
    ) {
        parent::__construct($view, $user);
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
        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $project = $this->projects->findById($projectId);
        $this->events->fire('task.created', [
            'task_id'      => $id,
            'title'        => $task['title'],
            'project_name' => $project['name'],
            'actor_name'   => $this->user['name'],
            'url'          => \abs_url('/tasks/' . $id),
        ]);
        $this->activity->log(
            'task.created',
            (int)$this->user['id'],
            $projectId,
            $id,
            "added task '" . $task['title'] . "'",
            ['column_id' => $columnId]
        );
        Response::json(['ok' => true, 'task' => [
            'id' => (int)$task['id'],
            'title' => $task['title'],
            'column_id' => (int)$task['column_id'],
            'position' => (float)$task['position'],
            'priority' => $task['priority'] ?? 'none',
            'assignee_id' => $task['assignee_id'] ? (int)$task['assignee_id'] : null,
        ]]);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        if (!\App\Service\RolePolicy::canEditTask($this->user, $task, $this->members)) {
            Response::json(['error' => 'Only the task author or project owner can delete this task'], 403); return;
        }
        $project = $this->projects->findById((int)$task['project_id']);
        $this->tasks->delete($id);
        // Detach FKs from sibling entities so they don't carry a dangling
        // pointer (the task page would 404 and "open linked entity" buttons
        // would mislead).
        $this->formSubmissions->detachConverted('task', $id);
        $this->polls->detachSummaryTask($id);
        $this->taskLinks->deleteForTask($id);
        $this->events->fire('task.deleted', [
            'task_id'      => $id,
            'title'        => $task['title'],
            'project_name' => $project['name'] ?? '',
            'actor_name'   => $this->user['name'],
        ]);
        $this->activity->log(
            'task.deleted',
            (int)$this->user['id'],
            (int)$task['project_id'],
            null,
            "deleted task '" . $task['title'] . "'",
            ['task_id' => $id]
        );
        Response::json(['ok' => true]);
    }

    /**
     * Spin a task off into a new project. Copies:
     *  - title → name, description → description
     *  - attachments (duplicates rows pointing at the same files; both task and project keep them)
     *  - tags      (finds/creates a project-scope tag with same name/color for each task tag)
     */
    public function promoteToProject(Request $req, array $params): void {
        $id   = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        if (!\App\Service\RolePolicy::canPromoteTaskToProject($this->user)) {
            Response::json(['error' => 'Employees cannot create projects'], 403); return;
        }

        $name = trim((string)$task['title']);
        if ($name === '') { Response::json(['error' => 'Task has no title'], 422); return; }
        $description = (string)($task['description'] ?? '');

        $newId = $this->projects->create($name, $description !== '' ? $description : null, (int)$this->user['id']);
        $this->members->add($newId, (int)$this->user['id'], 'owner');
        $this->columns->seedDefaults($newId);

        // Duplicate attachment rows so the new project carries the same files
        // (filename column is a path, shared — files themselves are not copied).
        $attachments = $this->attachments->listFor('task', $id);
        foreach ($attachments as $a) {
            $this->attachments->create([
                'entity_type'   => 'project',
                'entity_id'     => $newId,
                'filename'      => $a['filename'],
                'original_name' => $a['original_name'],
                'mime'          => $a['mime'],
                'size'          => (int)$a['size'],
                'is_image'      => (int)$a['is_image'] === 1,
                'uploaded_by'   => (int)$this->user['id'],
            ]);
        }

        // Copy tags. Task tags live in 'task' scope, project tags in 'project'
        // scope — so we resolve/create a sibling tag in the project scope by name+color.
        $tagsRepo = $this->tags;
        foreach ($tagsRepo->listForTask($id) as $tg) {
            $projTagId = $tagsRepo->create('project', (string)$tg['name'], (string)($tg['color'] ?? '#8B7C68'));
            $tagsRepo->attachToProject($newId, $projTagId);
        }

        $this->events->fire('project.created', [
            'project_id' => $newId,
            'name'       => $name,
            'actor_name' => $this->user['name'],
            'url'        => \abs_url('/projects/' . $newId),
        ]);
        $this->activity->log(
            'project.created',
            (int)$this->user['id'],
            $newId,
            null,
            "spun off project '$name' from task '" . $task['title'] . "'",
            ['from_task_id' => $id, 'copied_attachments' => count($attachments)]
        );

        Response::json(['ok' => true, 'project_id' => $newId, 'url' => '/projects/' . $newId . '?tab=overview']);
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

        $cols = $this->columns->listForProject((int)$task['project_id']);
        $byId = [];
        foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
        // "To Do" = first non-backlog column by position
        $boardCols = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
        usort($boardCols, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
        $todoColId = $boardCols ? (int)$boardCols[0]['id'] : 0;
        $oldCol = $byId[(int)$task['column_id']] ?? null;
        $newCol = $byId[$columnId] ?? null;

        $subStatus = null;
        $clearSub  = false;
        if ($newCol && $columnId === $todoColId && $oldCol && (int)$oldCol['id'] !== $todoColId) {
            $fromBacklog = (int)($oldCol['is_backlog'] ?? 0) === 1;
            $fromDone    = (int)($oldCol['is_done'] ?? 0) === 1;
            if ($fromDone)        { $subStatus = 'reopened'; }
            elseif (!$fromBacklog) { $subStatus = 'returned'; }
        } elseif ($newCol && $columnId !== $todoColId && !empty($task['sub_status'])) {
            // Moving forward out of To Do — clear the badge
            $clearSub = true;
        }
        $this->tasks->move($taskId, $columnId, $position, $subStatus, $clearSub);

        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $newColName = $newCol['name'] ?? '';
        $this->events->fire('task.status_changed', [
            'task_id'    => $taskId,
            'title'      => $task['title'],
            'new_column' => $newColName,
            'actor_name' => $this->user['name'],
            'url'        => \abs_url('/tasks/' . $taskId),
        ]);
        $this->activity->log(
            'task.status_changed',
            (int)$this->user['id'],
            (int)$task['project_id'],
            $taskId,
            'moved to ' . ($newColName !== '' ? $newColName : 'another column'),
            ['column_id' => $columnId, 'new_column' => $newColName]
        );
        $fresh = $this->tasks->findById($taskId);
        Response::json(['ok' => true, 'sub_status' => $fresh['sub_status'] ?? null]);
    }

    public function listForColumn(Request $req, array $params): void {
        $projectId = (int)$params['id'];
        $columnId  = (int)$params['cid'];
        if (!$this->projects->findById($projectId)) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember($projectId);
        $offset = max(0, (int)($req->query['offset'] ?? 0));
        $limit  = min(200, max(1, (int)($req->query['limit'] ?? 50)));
        $rows   = $this->tasks->listForColumnPaged($columnId, $limit, $offset);
        $total  = $this->tasks->countForColumn($columnId);

        $taskIds = array_column($rows, 'id');
        $tagsByTask = [];
        $metaByTask = [];
        if ($taskIds) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT ttm.task_id, t.id, t.name, t.color
                 FROM task_tag_map ttm
                 INNER JOIN tags t ON t.id = ttm.tag_id
                 WHERE ttm.task_id IN ($placeholders)"
            );
            $stmt->execute($taskIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $tagsByTask[(int)$r['task_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
            }
            $stmt = $this->db->prepare(
                "SELECT t.id,
                        (SELECT COUNT(*) FROM comments WHERE entity_type='task' AND entity_id = t.id) AS comments,
                        (SELECT COUNT(*) FROM attachments WHERE entity_type='task' AND entity_id = t.id) AS attachments,
                        (SELECT COUNT(*) FROM task_links WHERE task_id = t.id OR linked_task_id = t.id) AS links
                 FROM tasks t WHERE t.id IN ($placeholders)"
            );
            $stmt->execute($taskIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $metaByTask[(int)$r['id']] = [
                    'comments' => (int)$r['comments'],
                    'attachments' => (int)$r['attachments'],
                    'links' => (int)$r['links'],
                ];
            }
        }

        $format  = ($req->query['format'] ?? 'card') === 'backlog' ? 'backlog' : 'card';
        $partial = $format === 'backlog' ? 'backlog-row' : 'kanban-card';

        // Resolve assignee names for backlog rendering
        if ($format === 'backlog') {
            $assignees = array_filter(array_unique(array_map(fn($r) => (int)($r['assignee_id'] ?? 0), $rows)));
            if ($assignees) {
                $placeholders = implode(',', array_fill(0, count($assignees), '?'));
                $stmt = $this->db->prepare("SELECT id, name FROM users WHERE id IN ($placeholders)");
                $stmt->execute(array_values($assignees));
                $byId = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $u) { $byId[(int)$u['id']] = $u['name']; }
                foreach ($rows as &$r) {
                    if (!empty($r['assignee_id']) && isset($byId[(int)$r['assignee_id']])) {
                        $r['assignee_name'] = $byId[(int)$r['assignee_id']];
                    }
                }
                unset($r);
            }
        }

        ob_start();
        foreach ($rows as $t) {
            $tags = $tagsByTask[(int)$t['id']] ?? [];
            $meta = $metaByTask[(int)$t['id']] ?? ['comments' => 0, 'attachments' => 0, 'links' => 0];
            require APP_ROOT . '/views/partials/' . $partial . '.php';
        }
        $html = ob_get_clean();
        Response::json([
            'ok'    => true,
            'html'  => $html,
            'count' => count($rows),
            'total' => $total,
        ]);
    }

    public function show(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::notFound(); return; }
        $projectId = (int)$task['project_id'];
        $project = $this->projects->findById($projectId);
        $isAdmin = \App\Service\RolePolicy::isAdmin($this->user);
        if (!$isAdmin && !$this->members->isMember($projectId, (int)$this->user['id'])) {
            Response::forbidden(); return;
        }
        $columns = $this->columns->listForProject($projectId);
        $members = $this->members->list($projectId);
        $comments = $this->comments->listFor('task', $id);
        $attachments = $this->attachments->listFor('task', $id);
        $commentIds = array_column($comments, 'id');
        $commentAttachments = [];
        if ($commentIds) {
            $placeholders = implode(',', array_fill(0, count($commentIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT * FROM attachments WHERE entity_type = 'comment' AND entity_id IN ($placeholders) ORDER BY created_at ASC"
            );
            $stmt->execute($commentIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                $commentAttachments[(int)$a['entity_id']][] = $a;
            }
        }
        // Files attached on comments — flattened for the "From comments" panel section
        $commentAttachmentsFlat = [];
        foreach ($commentAttachments as $cid => $list) {
            foreach ($list as $a) {
                $a['comment_id'] = $cid;
                $commentAttachmentsFlat[] = $a;
            }
        }
        // Bare URLs inside comment bodies — collected once per URL with first author
        $commentLinks = [];
        $seenUrls = [];
        foreach ($comments as $c) {
            if (preg_match_all('#https?://[^\s<>"\)\]]+#i', (string)$c['body'], $m)) {
                foreach ($m[0] as $url) {
                    $url = rtrim($url, '.,;:!?');
                    if ($url === '' || isset($seenUrls[$url])) continue;
                    $seenUrls[$url] = true;
                    $commentLinks[] = [
                        'url'         => $url,
                        'author_name' => $c['author_name'] ?? '',
                        'comment_id'  => (int)$c['id'],
                    ];
                }
            }
        }
        $taskTags    = $this->tags->listForTask($id);
        $allTaskTags = $this->tags->listAll();
        $linkedTasks = $this->taskLinks->listForTask($id);
        $createdBy = $this->users->findById((int)$task['created_by']);
        $csrfToken = $this->csrf->token();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'projects', 'csrfToken' => $csrfToken,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user,
            'crumb' => $project['name'],
            'crumbExtra' => '<span class="topbar__title-sub">/ Task #' . (int)$id . '</span>',
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
                'canEditTask' => \App\Service\RolePolicy::canEditTask($this->user, $task, $this->members),
                'canCreateProject' => \App\Service\RolePolicy::canCreateProject($this->user),
                'taskTags'    => $taskTags,
                'allTaskTags' => $allTaskTags,
                'commentAttachments' => $commentAttachments,
                'commentAttachmentsFlat' => $commentAttachmentsFlat,
                'commentLinks' => $commentLinks,
                'tabCounts'   => $this->tasks->countsForProject($projectId),
                'linkedTasks' => $linkedTasks,
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
        $db  = $this->db;

        if ($q !== '' || $tag !== '') {
            $qLower = mb_strtolower($q);
            if ($tag !== '') {
                $sql = "SELECT DISTINCT t.id FROM tasks t
                        INNER JOIN task_tag_map m ON m.task_id = t.id
                        INNER JOIN tags g ON g.id = m.tag_id
                        WHERE t.project_id = ? AND LOWER(g.name) = LOWER(?)"
                      . ($q !== '' ? " AND LOWER(t.title) LIKE ?" : "")
                      . " LIMIT 200";
                $p = [$projectId, $tag];
                if ($q !== '') $p[] = '%' . $qLower . '%';
                $stmt = $db->prepare($sql);
                $stmt->execute($p);
            } else {
                $stmt = $db->prepare(
                    "SELECT id FROM tasks WHERE project_id = ? AND LOWER(title) LIKE ? LIMIT 200"
                );
                $stmt->execute([$projectId, '%' . $qLower . '%']);
            }
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        } else {
            $ids = null; // null = no filter
        }
        Response::json(['ok' => true, 'ids' => $ids]);
    }

    public function searchLinkable(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $projectId = (int)$task['project_id'];
        $this->assertMember($projectId);

        $q = trim($req->query['q'] ?? '');
        $linkedIds = $this->taskLinks->linkedIds($id);
        $exclude = array_merge([$id], $linkedIds);

        $placeholders = implode(',', array_fill(0, count($exclude), '?'));
        $sql = "SELECT t.id, t.title, c.name AS column_name, c.color AS column_color,
                       c.is_done AS column_is_done, c.is_backlog AS column_is_backlog
                FROM tasks t
                INNER JOIN task_columns c ON c.id = t.column_id
                WHERE t.project_id = ? AND t.id NOT IN ($placeholders)";
        $args = array_merge([$projectId], $exclude);
        if ($q !== '') {
            $sql .= ' AND (LOWER(t.title) LIKE ? OR CAST(t.id AS TEXT) = ?)';
            $args[] = '%' . mb_strtolower($q) . '%';
            $args[] = $q;
        }
        $sql .= ' ORDER BY t.id DESC LIMIT 30';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        Response::json(['ok' => true, 'tasks' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    public function link(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $projectId = (int)$task['project_id'];
        $this->assertMember($projectId);

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $other = (int)($data['linked_task_id'] ?? 0);
        if ($other <= 0 || $other === $id) { Response::json(['error' => 'Invalid task'], 422); return; }
        $otherTask = $this->tasks->findById($other);
        if (!$otherTask || (int)$otherTask['project_id'] !== $projectId) {
            Response::json(['error' => 'Task must be in the same project'], 422); return;
        }
        $created = $this->taskLinks->link($id, $other, (int)$this->user['id']);

        if ($created) {
            $project = $this->projects->findById($projectId);
            // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
            $this->events->fire('task.linked', [
                'task_id'        => $id,
                'task_title'     => $task['title'],
                'linked_task_id' => $other,
                'linked_title'   => $otherTask['title'],
                'project_name'   => $project['name'] ?? '',
                'actor_name'     => $this->user['name'],
                'url'            => \abs_url('/tasks/' . $id),
            ]);
            $this->activity->log(
                'task.linked',
                (int)$this->user['id'],
                $projectId,
                $id,
                "linked '" . $task['title'] . "' ↔ '" . $otherTask['title'] . "'",
                ['linked_task_id' => $other]
            );
        }

        // Return the linked-task row for instant UI render
        $stmt = $this->db->prepare(
            "SELECT t.id, t.title, t.column_id, t.priority, t.sub_status,
                    c.name AS column_name, c.color AS column_color,
                    c.is_done AS column_is_done, c.is_backlog AS column_is_backlog
             FROM tasks t INNER JOIN task_columns c ON c.id = t.column_id
             WHERE t.id = ?"
        );
        $stmt->execute([$other]);
        Response::json(['ok' => true, 'task' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    public function unlink(Request $req, array $params): void {
        $id = (int)$params['id'];
        $other = (int)$params['otherId'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        $otherTask = $this->tasks->findById($other);
        $this->taskLinks->unlink($id, $other);
        $project = $this->projects->findById((int)$task['project_id']);
        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        $this->events->fire('task.unlinked', [
            'task_id'        => $id,
            'task_title'     => $task['title'],
            'linked_task_id' => $other,
            'linked_title'   => $otherTask['title'] ?? ('#' . $other),
            'project_name'   => $project['name'] ?? '',
            'actor_name'     => $this->user['name'],
            'url'            => \abs_url('/tasks/' . $id),
        ]);
        $this->activity->log(
            'task.unlinked',
            (int)$this->user['id'],
            (int)$task['project_id'],
            $id,
            "unlinked '" . $task['title'] . "' ↔ '" . ($otherTask['title'] ?? ('#' . $other)) . "'",
            ['linked_task_id' => $other]
        );
        Response::json(['ok' => true]);
    }

    public function update(Request $req, array $params): void {
        $id = (int)$params['id'];
        $task = $this->tasks->findById($id);
        if (!$task) { Response::json(['error' => 'Not found'], 404); return; }
        $this->assertMember((int)$task['project_id']);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        // Title/description are "structural" edits — restricted to the author,
        // project manager (owner), or admin. Status / assignee / due / priority
        // remain open to any project member (employees can re-prioritise, move).
        $touchesRestricted = isset($data['title']) || array_key_exists('description', $data);
        if ($touchesRestricted && !\App\Service\RolePolicy::canEditTask($this->user, $task, $this->members)) {
            Response::json(['error' => 'Only the task author or project owner can edit title/description'], 403); return;
        }

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
            $newColumnId = (int)$data['column_id'];
            // Validate the column belongs to this task's project — otherwise a
            // member of two projects could move a task across project boundaries
            // just by sending the foreign column id.
            $cols = $this->columns->listForProject((int)$task['project_id']);
            $byId = [];
            foreach ($cols as $c) { $byId[(int)$c['id']] = $c; }
            if (!isset($byId[$newColumnId])) {
                Response::json(['error' => 'Column does not belong to this project'], 422); return;
            }
            $fields['column_id'] = $newColumnId;
            if ($newColumnId !== (int)$task['column_id']) {
                $boardCols = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
                usort($boardCols, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
                $todoColId = $boardCols ? (int)$boardCols[0]['id'] : 0;
                $oldCol = $byId[(int)$task['column_id']] ?? null;
                if ($newColumnId === $todoColId && $oldCol && (int)$oldCol['id'] !== $todoColId) {
                    $fromBacklog = (int)($oldCol['is_backlog'] ?? 0) === 1;
                    $fromDone    = (int)($oldCol['is_done'] ?? 0) === 1;
                    if ($fromDone)        { $fields['sub_status'] = 'reopened'; }
                    elseif (!$fromBacklog) { $fields['sub_status'] = 'returned'; }
                } elseif ($newColumnId !== $todoColId) {
                    $fields['sub_status'] = null;
                }
            }
        }
        if (array_key_exists('assignee_id', $data)) {
            $aid = $data['assignee_id'];
            $fields['assignee_id'] = $aid === null || $aid === '' ? null : (int)$aid;
        }
        if (array_key_exists('due_date', $data)) {
            $fields['due_date'] = $data['due_date'] === '' ? null : $data['due_date'];
        }
        if (array_key_exists('priority', $data)) {
            $p = (string)$data['priority'];
            $allowedP = ['none', 'low', 'medium', 'high', 'urgent'];
            $fields['priority'] = in_array($p, $allowedP, true) ? $p : 'none';
        }

        $this->tasks->update($id, $fields);
        $fresh = $this->tasks->findById($id);
        // baseUrl resolved per-call via \abs_url() helper (defensive vs empty APP_URL)
        if (isset($fields['column_id']) && (int)$fields['column_id'] !== (int)$task['column_id']) {
            $cols = $this->columns->listForProject((int)$task['project_id']);
            $newColName = '';
            foreach ($cols as $c) if ((int)$c['id'] === (int)$fields['column_id']) { $newColName = $c['name']; break; }
            $this->events->fire('task.status_changed', [
                'task_id'    => $id,
                'title'      => $fresh['title'],
                'new_column' => $newColName,
                'actor_name' => $this->user['name'],
                'url'        => \abs_url('/tasks/' . $id),
            ]);
            $this->activity->log(
                'task.status_changed',
                (int)$this->user['id'],
                (int)$task['project_id'],
                $id,
                'moved to ' . ($newColName !== '' ? $newColName : 'another column'),
                ['column_id' => (int)$fields['column_id'], 'new_column' => $newColName]
            );
        }
        if (array_key_exists('assignee_id', $fields) && (int)($fields['assignee_id'] ?? 0) !== (int)($task['assignee_id'] ?? 0)) {
            $assigneeName = 'Unassigned';
            if ($fields['assignee_id']) {
                $assignee = $this->users->findById((int)$fields['assignee_id']);
                if ($assignee) $assigneeName = $assignee['name'];
            }
            $this->events->fire('task.assignee_changed', [
                'task_id'       => $id,
                'title'         => $fresh['title'],
                'assignee_name' => $assigneeName,
                'actor_name'    => $this->user['name'],
                'url'           => \abs_url('/tasks/' . $id),
            ]);
        }
        // Description is sanitised HTML from Quill — enhance plain links into preview cards
        $descHtml = \App\Service\LinkPreview::enhance((string)($fresh['description'] ?? ''));
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
                'priority' => $fresh['priority'] ?? 'none',
                'sub_status' => $fresh['sub_status'] ?? null,
            ],
        ]);
    }
}
