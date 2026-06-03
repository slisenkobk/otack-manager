<?php
declare(strict_types=1);
namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\ActivityLogRepository;
use App\Repository\FormRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\SettingsRepository;
use App\Service\EventBus;
use App\Service\RolePolicy;
use App\View\Renderer;

final class FormController extends BaseController
{
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'radio', 'checkbox'];

    public function __construct(
        Renderer $view,
        ?array $user,
        private FormRepository $forms,
        private ProjectRepository $projects,
        private ProjectMemberRepository $members,
        private SettingsRepository $settings,
        private ActivityLogRepository $activity,
        private EventBus $events,
    ) {
        parent::__construct($view, $user);
        if (!RolePolicy::canManageForms($this->user)) {
            Response::forbidden('Forms are managed by admins and managers'); exit;
        }
    }

    public function index(Request $req, array $params = []): void {
        $query = trim((string)($req->query['q'] ?? ''));
        $list  = $this->forms->listAll($query);
        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'forms', 'csrfToken' => $csrf,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => 'Forms',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => 'Forms',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('forms/index', [
                'forms' => $list,
                'query' => $query,
            ]),
        ]));
    }

    public function builder(Request $req, array $params = []): void {
        $id = isset($params['id']) ? (int)$params['id'] : 0;
        $form = $id ? $this->forms->findById($id) : null;
        if ($id && !$form) { Response::notFound(); return; }

        $isAdmin   = RolePolicy::isAdmin($this->user);
        $projects  = $this->projects->listForUser((int)$this->user['id'], $isAdmin);

        $csrf = $this->csrfToken();
        $sidebar = $this->view->render('partials/sidebar', [
            'user' => $this->user, 'activeNav' => 'forms', 'csrfToken' => $csrf,
        ]);
        $topbar = $this->view->render('partials/topbar', [
            'user' => $this->user, 'crumb' => $form ? ('Edit form: ' . $form['title']) : 'New form',
        ]);
        Response::html($this->view->render('layouts/main', [
            'title'     => $form ? 'Edit form' : 'New form',
            'csrfToken' => $csrf,
            'sidebar'   => $sidebar,
            'topbar'    => $topbar,
            'content'   => $this->view->render('forms/builder', [
                'form'       => $form,
                'csrfToken'  => $csrf,
                'fieldTypes' => self::FIELD_TYPES,
                'projects'   => $projects,
            ]),
        ]));
    }

    public function save(Request $req, array $params = []): void {
        $data = $req->jsonBody([]);
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') { Response::json(['error' => 'Title required'], 422); return; }
        // Description is Quill HTML — sanitise via the same pipeline used for
        // task/project descriptions to strip script/style/etc.
        $descriptionRaw = (string)($data['description'] ?? '');
        $description    = trim($descriptionRaw) === '' ? '' : \App\Service\HtmlSanitizer::clean($descriptionRaw);
        $locale = (string)($data['locale'] ?? '');
        if (!in_array($locale, available_locales(), true)) {
            $locale = (string)$this->settings->get('default_locale', 'en');
            if (!in_array($locale, available_locales(), true)) $locale = 'en';
        }
        $fields = $this->normaliseFields($data['fields'] ?? []);
        $rawFooter = is_array($data['footer'] ?? null) ? $data['footer'] : [];
        [$projectId, $autoCreateTask, $taskTpl] = $this->normaliseIntegration($data);
        // Plain-text override for the public "thanks" page. NULL means "fall
        // back to the localized default" on the public view, so distinguish
        // empty-string from "not set" by trimming and storing NULL when blank.
        $successRaw = trim((string)($data['success_message'] ?? ''));
        $successMessage = $successRaw === '' ? null : mb_substr($successRaw, 0, 2000);
        // The note field is rendered as raw HTML on the public form (so it can
        // carry formatting from Settings' Quill editor). Run any per-form override
        // through the same allow-list sanitiser to close the stored-XSS gap.
        $noteRaw = trim((string)($rawFooter['note'] ?? ''));
        $note    = $noteRaw === '' ? '' : \App\Service\HtmlSanitizer::clean($noteRaw);
        $footer = [
            'show_company' => !empty($rawFooter['show_company']),
            'show_email'   => !empty($rawFooter['show_email']),
            'show_phone'   => !empty($rawFooter['show_phone']),
            'show_address' => !empty($rawFooter['show_address']),
            'show_note'    => !empty($rawFooter['show_note']),
            'company_name' => trim((string)($rawFooter['company_name'] ?? '')),
            'email'        => trim((string)($rawFooter['email']        ?? '')),
            'phone'        => trim((string)($rawFooter['phone']        ?? '')),
            'address'      => trim((string)($rawFooter['address']      ?? '')),
            'note'         => $note,
        ];

        if (!empty($params['id'])) {
            $id = (int)$params['id'];
            $existing = $this->forms->findById($id);
            if (!$existing) { Response::json(['error' => 'Not found'], 404); return; }
            // managers can only edit forms they created (admin: any)
            if (!RolePolicy::isAdmin($this->user) && (int)$existing['created_by'] !== (int)$this->user['id']) {
                Response::json(['error' => 'Forbidden'], 403); return;
            }
            $this->forms->update($id, [
                'title'               => $title,
                'description'         => $description,
                'fields_json'         => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'footer_json'         => json_encode($footer, JSON_UNESCAPED_UNICODE),
                'locale'              => $locale,
                'project_id'          => $projectId,
                'auto_create_task'    => $autoCreateTask ? 1 : 0,
                'task_title_template' => $taskTpl,
                'success_message'     => $successMessage,
            ]);
            $this->activity->log('form.updated', (int)$this->user['id'], null, null,
                "updated form '$title'", ['form_id' => $id]);
            $this->events->fire('form.updated', [
                'form_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
            ]);
            Response::json(['ok' => true, 'id' => $id]);
            return;
        }

        $id = $this->forms->create(
            $title,
            $description !== '' ? $description : null,
            $fields,
            $footer,
            (int)$this->user['id'],
            $locale,
            $projectId,
            $autoCreateTask,
            $taskTpl,
            $successMessage
        );
        $this->activity->log('form.created', (int)$this->user['id'], null, null,
            "created form '$title'", ['form_id' => $id]);
        $this->events->fire('form.created', [
            'form_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function regenerateHash(Request $req, array $params): void {
        $id = (int)$params['id'];
        $form = $this->forms->findById($id);
        if (!$form) { Response::json(['error' => 'Not found'], 404); return; }
        if (!RolePolicy::isAdmin($this->user) && (int)$form['created_by'] !== (int)$this->user['id']) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }
        $hash = $this->forms->rotateHash($id);
        Response::json([
            'ok'   => true,
            'hash' => $hash,
            'url'  => \abs_url('/f/' . $hash),
        ]);
    }

    public function delete(Request $req, array $params): void {
        $id = (int)$params['id'];
        $form = $this->forms->findById($id);
        if (!$form) { Response::json(['error' => 'Not found'], 404); return; }
        if (!RolePolicy::isAdmin($this->user) && (int)$form['created_by'] !== (int)$this->user['id']) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }
        $title = $form['title'];
        $this->forms->delete($id);
        $this->activity->log('form.deleted', (int)$this->user['id'], null, null,
            "deleted form '$title'", ['form_id' => $id]);
        $this->events->fire('form.deleted', [
            'form_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true]);
    }

    /**
     * Clone an existing form. Same title (with "(copy)" suffix), same fields,
     * same footer + integration settings, but a fresh hash so the public URL
     * is new and the original form's link stays live.
     */
    public function copy(Request $req, array $params): void {
        $id  = (int)$params['id'];
        $src = $this->forms->findById($id);
        if (!$src) { Response::json(['error' => 'Not found'], 404); return; }
        if (!RolePolicy::isAdmin($this->user) && (int)$src['created_by'] !== (int)$this->user['id']) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }

        $fields   = json_decode((string)$src['fields_json'], true) ?: [];
        $footer   = json_decode((string)$src['footer_json'], true) ?: [];
        $newTitle = mb_substr(trim($src['title']) . ' (copy)', 0, 200);

        // Re-validate the attached project: source may have been attached to a
        // project the current user can no longer reach; in that case we drop
        // the link rather than transferring it silently. When the link is
        // dropped, auto-task settings must be cleared too so the copy doesn't
        // end up in a contradictory state (auto_create_task=1, project_id=NULL).
        $projectId = !empty($src['project_id']) ? (int)$src['project_id'] : null;
        if ($projectId !== null && !$this->canAttachToProject($projectId)) {
            $projectId = null;
        }
        $copyAutoTask = $projectId !== null && !empty($src['auto_create_task']);
        $copyTaskTpl  = $projectId !== null && !empty($src['task_title_template'])
            ? (string)$src['task_title_template']
            : null;
        $copySuccess  = !empty($src['success_message']) ? (string)$src['success_message'] : null;

        $newId = $this->forms->create(
            $newTitle,
            $src['description'] !== '' ? (string)$src['description'] : null,
            $fields,
            $footer,
            (int)$this->user['id'],
            (string)($src['locale'] ?? 'en'),
            $projectId,
            $copyAutoTask,
            $copyTaskTpl,
            $copySuccess
        );

        $this->activity->log('form.created', (int)$this->user['id'], null, null,
            "duplicated form '{$src['title']}' as '$newTitle'",
            ['form_id' => $newId, 'source_form_id' => $id]);
        $this->events->fire('form.created', [
            'form_id' => $newId, 'title' => $newTitle, 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true, 'id' => $newId, 'url' => '/forms/' . $newId]);
    }

    /**
     * Normalise the project-integration fields. Drops project_id silently if
     * the user cannot reach the project (manager scope) — the UI shouldn't
     * offer those choices in the first place but we double-check on save.
     *
     * @return array{0:?int,1:bool,2:?string}
     */
    private function normaliseIntegration(array $data): array {
        $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : null;
        if ($projectId !== null && !$this->canAttachToProject($projectId)) $projectId = null;

        $auto = $projectId !== null && !empty($data['auto_create_task']);
        $tpl  = trim((string)($data['task_title_template'] ?? ''));
        $tpl  = $tpl !== '' ? mb_substr($tpl, 0, 500) : null;

        return [$projectId, $auto, $tpl];
    }

    /** Admin: any project. Manager: only projects they're a member of. */
    private function canAttachToProject(int $projectId): bool {
        $project = $this->projects->findById($projectId);
        if (!$project) return false;
        if (RolePolicy::isAdmin($this->user)) return true;
        return $this->members->isMember($projectId, (int)$this->user['id']);
    }

    /**
     * Trim, validate and key-by-position the field array submitted by the builder.
     * Each field: { key, type, label, required, options[] }
     */
    private function normaliseFields(array $raw): array {
        $out = [];
        $usedKeys = []; // dedup across the form so two fields can't share a key
        foreach ($raw as $i => $f) {
            $type = (string)($f['type'] ?? '');
            if (!in_array($type, self::FIELD_TYPES, true)) continue;
            $label = trim((string)($f['label'] ?? ''));
            if ($label === '') continue;
            $base = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string)($f['key'] ?? ''))));
            if ($base === '' || $base === null) $base = 'field_' . ($i + 1);
            // Resolve collisions deterministically so a submission lookup by key
            // can't have one answer silently overwrite another.
            $key = $base;
            $n = 2;
            while (isset($usedKeys[$key])) { $key = $base . '_' . $n; $n++; }
            $usedKeys[$key] = true;

            $required = !empty($f['required']);
            $options = [];
            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                foreach ((array)($f['options'] ?? []) as $opt) {
                    $opt = trim((string)$opt);
                    if ($opt !== '') $options[] = $opt;
                }
            }
            $out[] = [
                'key'      => $key,
                'type'     => $type,
                'label'    => $label,
                'required' => $required,
                'options'  => $options,
            ];
        }
        return $out;
    }
}
