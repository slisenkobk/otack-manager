<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\TaskColumnRepository;
use App\Repository\TaskRepository;
use App\Service\RolePolicy;
use App\View\Renderer;

final class FormDataController extends BaseController
{
    public function __construct(
        Renderer $view,
        ?array $user,
        private FormRepository $forms,
        private FormSubmissionRepository $subs,
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private TaskColumnRepository $columns,
        private TaskRepository $tasks,
        private ActivityLogRepository $activity,
    ) {
        parent::__construct($view, $user);
        if (!RolePolicy::canViewFormsData($this->user)) {
            Response::forbidden('Forms Data is for admins and managers'); exit;
        }
    }

    public function index(Request $req, array $params = []): void {
        $formId = isset($req->query['form_id']) && $req->query['form_id'] !== ''
            ? (int)$req->query['form_id'] : null;
        $status = (string)($req->query['status'] ?? '');
        $query  = trim((string)($req->query['q'] ?? ''));
        $rows  = $this->subs->listAll($formId, $status !== '' ? $status : null, $query);
        $forms = $this->forms->listAll();
        $byStatus = $this->subs->countByStatus();

        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'forms-data', 'csrfToken' => $csrf,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Forms Data',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Forms Data',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('forms-data/index', [
                'rows'        => $rows,
                'forms'       => $forms,
                'currentForm' => $formId,
                'currentStatus' => $status,
                'query'       => $query,
                'byStatus'    => $byStatus,
            ]),
        ]));
    }

    public function show(Request $req, array $params): void {
        $id = (int)$params['id'];
        $sub = $this->subs->findById($id);
        if (!$sub) { Response::notFound(); return; }
        $form = $this->forms->findById((int)$sub['form_id']);
        if (!$form) { Response::notFound(); return; }
        // Manager can only open submissions of forms they created; admin sees all.
        if (!$this->canManage($sub)) {
            Response::forbidden('This submission belongs to a form you do not own'); return;
        }

        $fields = json_decode((string)$form['fields_json'], true) ?: [];
        $data   = json_decode((string)$sub['data_json'], true) ?: [];
        $footer = json_decode((string)$sub['footer_json'], true) ?: [];

        // Projects list for the convert-to-task picker (manager scope: visible projects only).
        $isAdmin = RolePolicy::isAdmin($this->user);
        $projects = $this->projects->listForUser((int)$this->user['id'], $isAdmin);

        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'forms-data', 'csrfToken' => $csrf,
        ]);
        $topbar  = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Submission #' . $id,
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Submission #' . $id,
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('forms-data/show', [
                'sub'      => $sub,
                'form'     => $form,
                'fields'   => $fields,
                'answers'  => $data,
                'footer'   => $footer,
                'projects' => $projects,
            ]),
        ]));
    }

    public function setStatus(Request $req, array $params): void {
        $id = (int)$params['id'];
        $sub = $this->subs->findById($id);
        if (!$sub) { Response::json(['error' => 'Not found'], 404); return; }
        $data = $req->jsonBody([]);
        $status = (string)($data['status'] ?? '');
        if (!in_array($status, ['new', 'in_progress', 'rejected', 'done'], true)) {
            Response::json(['error' => 'Invalid status'], 422); return;
        }
        if (!$this->canManage($sub)) {
            Response::json(['error' => 'Only the form owner / admin can manage this submission'], 403); return;
        }
        $this->subs->setStatus($id, $status);
        Response::json(['ok' => true, 'status' => $status]);
    }

    /**
     * Convert a submission to either a new project or a new task.
     * Body: { type: 'project'|'task', project_id?: int (when type=task) }
     */
    public function promote(Request $req, array $params): void {
        $id = (int)$params['id'];
        $sub = $this->subs->findById($id);
        if (!$sub) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($sub)) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }
        // Prevent re-conversion (double click / race) — the source of truth is
        // the submission's status. detachConverted() on project/task delete
        // resets it back to 'new', so re-promoting is still possible after a cleanup.
        if (in_array($sub['status'], ['converted_task', 'converted_project'], true)) {
            Response::json(['error' => 'Submission has already been converted'], 422); return;
        }
        $form = $this->forms->findById((int)$sub['form_id']);
        if (!$form) { Response::json(['error' => 'Form gone'], 404); return; }

        $payload = $req->jsonBody([]);
        $type    = (string)($payload['type'] ?? '');

        $fields = json_decode((string)$form['fields_json'], true) ?: [];
        $data   = json_decode((string)$sub['data_json'], true) ?: [];
        [$title, $description] = $this->renderTitleDescription($form, $fields, $data, $sub);

        if ($type === 'project') {
            if (!RolePolicy::canCreateProject($this->user)) {
                Response::json(['error' => 'Cannot create projects'], 403); return;
            }
            $newId = $this->projects->create($title, $description, (int)$this->user['id']);
            $this->members->add($newId, (int)$this->user['id'], 'owner');
            $this->columns->seedDefaults($newId);
            $this->subs->setStatus($id, 'converted_project', null, $newId);
            $this->activity->log('project.created', (int)$this->user['id'], $newId, null,
                "created project '$title' from submission #$id", ['submission_id' => $id]);
            Response::json(['ok' => true, 'url' => '/projects/' . $newId . '?tab=overview']);
            return;
        }

        if ($type === 'task') {
            $projectId = (int)($payload['project_id'] ?? 0);
            $project   = $projectId ? $this->projects->findById($projectId) : null;
            if (!$project) { Response::json(['error' => 'Pick a project'], 422); return; }
            $isAdmin = RolePolicy::isAdmin($this->user);
            if (!$isAdmin && !$this->members->isMember($projectId, (int)$this->user['id'])) {
                Response::json(['error' => 'You are not a member of that project'], 403); return;
            }
            // Drop the new task into the To-Do column (leftmost non-backlog column).
            $cols = $this->columns->listForProject($projectId);
            $board = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
            usort($board, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
            $todoCol = $board[0] ?? $cols[0] ?? null;
            if (!$todoCol) { Response::json(['error' => 'Project has no columns'], 422); return; }

            $taskId = $this->tasks->create($projectId, (int)$todoCol['id'], $title, (int)$this->user['id']);
            if ($description !== '') {
                // cleanRich() to match the task-description write paths in
                // TaskController::update() and TasksHandler::sanitizeDescription().
                // Not every writer into tasks.description switched:
                // PublicFormController::submit() and PollController still use
                // clean(). All four are behaviour-identical today —
                // FormTemplate::renderTaskBody() emits <p>/<strong>/<em>/<br>;
                // the poll body builder also emits <ul>/<li>. All of those
                // tags are on both allow-lists — so this is a policy-
                // consistency choice, not a behaviour change.
                $this->tasks->update($taskId, ['description' => \App\Service\HtmlSanitizer::cleanRich($description)]);
            }
            $this->subs->setStatus($id, 'converted_task', $taskId, null);
            $this->activity->log('task.created', (int)$this->user['id'], $projectId, $taskId,
                "created task '$title' from submission #$id", ['submission_id' => $id]);
            Response::json(['ok' => true, 'url' => '/tasks/' . $taskId]);
            return;
        }

        Response::json(['error' => 'Unknown promote type'], 422);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        $sub = $this->subs->findById($id);
        if (!$sub) { Response::json(['error' => 'Not found'], 404); return; }
        if (!$this->canManage($sub)) { Response::json(['error' => 'Forbidden'], 403); return; }
        $this->subs->delete($id);
        Response::json(['ok' => true]);
    }

    /** Managers can manage only their own forms' submissions; admins — all. */
    private function canManage(array $sub): bool {
        if (RolePolicy::isAdmin($this->user)) return true;
        $form = $this->forms->findById((int)$sub['form_id']);
        return $form && (int)$form['created_by'] === (int)$this->user['id'];
    }

    /**
     * Build a sensible title + description for a converted project/task:
     *  title  → first text-ish field's value (fallback to form title + #id)
     *  desc   → markdown-ish dump of all answers
     */
    private function renderTitleDescription(array $form, array $fields, array $data, array $sub): array {
        $title = '';
        foreach ($fields as $f) {
            if (in_array($f['type'] ?? '', ['text', 'textarea', 'email', 'phone'], true)) {
                $v = $data[$f['key']] ?? '';
                if (is_string($v) && trim($v) !== '') { $title = trim($v); break; }
            }
        }
        if ($title === '') $title = $form['title'] . ' #' . $sub['id'];
        $title = mb_substr($title, 0, 120);
        $body  = \App\Service\FormTemplate::renderTaskBody($form, $fields, $data, (int)$sub['id']);
        return [$title, $body];
    }
}
