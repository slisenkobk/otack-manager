<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\RolePolicy;

final class FormDataController extends BaseController
{
    private FormRepository           $forms;
    private FormSubmissionRepository $subs;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        if (!RolePolicy::canViewFormsData($this->user)) {
            Response::forbidden('Forms Data is for admins and managers'); exit;
        }
        $this->forms = App::make('forms');
        $this->subs  = App::make('form_submissions');
    }

    public function index(Request $req, array $params = []): void {
        $formId = isset($req->query['form_id']) && $req->query['form_id'] !== ''
            ? (int)$req->query['form_id'] : null;
        $status = (string)($req->query['status'] ?? '');
        $rows  = $this->subs->listAll($formId, $status !== '' ? $status : null);
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

        $fields = json_decode((string)$form['fields_json'], true) ?: [];
        $data   = json_decode((string)$sub['data_json'], true) ?: [];
        $footer = json_decode((string)$sub['footer_json'], true) ?: [];

        // Projects list for the convert-to-task picker (manager scope: visible projects only).
        $isAdmin = $this->user['role'] === 'admin';
        $projects = App::make('projects')->listForUser((int)$this->user['id'], $isAdmin);

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
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
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
        $form = $this->forms->findById((int)$sub['form_id']);
        if (!$form) { Response::json(['error' => 'Form gone'], 404); return; }

        $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $type    = (string)($payload['type'] ?? '');

        $fields = json_decode((string)$form['fields_json'], true) ?: [];
        $data   = json_decode((string)$sub['data_json'], true) ?: [];
        [$title, $description] = $this->renderTitleDescription($form, $fields, $data, $sub);

        if ($type === 'project') {
            if (!RolePolicy::canCreateProject($this->user)) {
                Response::json(['error' => 'Cannot create projects'], 403); return;
            }
            $newId = App::make('projects')->create($title, $description, (int)$this->user['id']);
            App::make('members')->add($newId, (int)$this->user['id'], 'owner');
            App::make('columns')->seedDefaults($newId);
            $this->subs->setStatus($id, 'converted_project', null, $newId);
            App::make('activity')->log('project.created', (int)$this->user['id'], $newId, null,
                "created project '$title' from submission #$id", ['submission_id' => $id]);
            Response::json(['ok' => true, 'url' => '/projects/' . $newId . '?tab=overview']);
            return;
        }

        if ($type === 'task') {
            $projectId = (int)($payload['project_id'] ?? 0);
            $project   = $projectId ? App::make('projects')->findById($projectId) : null;
            if (!$project) { Response::json(['error' => 'Pick a project'], 422); return; }
            $isAdmin = $this->user['role'] === 'admin';
            if (!$isAdmin && !App::make('members')->isMember($projectId, (int)$this->user['id'])) {
                Response::json(['error' => 'You are not a member of that project'], 403); return;
            }
            // Drop the new task into the To-Do column (leftmost non-backlog column).
            $cols = App::make('columns')->listForProject($projectId);
            $board = array_values(array_filter($cols, fn($c) => (int)($c['is_backlog'] ?? 0) === 0));
            usort($board, fn($a, $b) => (int)$a['position'] <=> (int)$b['position']);
            $todoCol = $board[0] ?? $cols[0] ?? null;
            if (!$todoCol) { Response::json(['error' => 'Project has no columns'], 422); return; }

            $taskId = App::make('tasks')->create($projectId, (int)$todoCol['id'], $title, (int)$this->user['id']);
            if ($description !== '') {
                App::make('tasks')->update($taskId, ['description' => \App\Service\HtmlSanitizer::clean($description)]);
            }
            $this->subs->setStatus($id, 'converted_task', $taskId, null);
            App::make('activity')->log('task.created', (int)$this->user['id'], $projectId, $taskId,
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

        $lines = ['<p><em>From form: ' . htmlspecialchars($form['title'], ENT_QUOTES, 'UTF-8') . ' / submission #' . (int)$sub['id'] . '</em></p>'];
        foreach ($fields as $f) {
            $v = $data[$f['key']] ?? '';
            if (is_array($v)) $v = implode(', ', $v);
            $v = trim((string)$v);
            if ($v === '') continue;
            $lines[] = '<p><strong>' . htmlspecialchars($f['label'], ENT_QUOTES, 'UTF-8') . ':</strong><br>'
                     . nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return [$title, implode('', $lines)];
    }
}
