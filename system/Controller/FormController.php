<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FormRepository;
use App\Service\RolePolicy;

final class FormController extends BaseController
{
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'select', 'radio', 'checkbox'];

    private FormRepository $forms;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        if (!RolePolicy::canManageForms($this->user)) {
            Response::forbidden('Forms are managed by admins and managers'); exit;
        }
        $this->forms = App::make('forms');
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
            ]),
        ]));
    }

    public function save(Request $req, array $params = []): void {
        $data = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') { Response::json(['error' => 'Title required'], 422); return; }
        // Description is Quill HTML — sanitise via the same pipeline used for
        // task/project descriptions to strip script/style/etc.
        $descriptionRaw = (string)($data['description'] ?? '');
        $description    = trim($descriptionRaw) === '' ? '' : \App\Service\HtmlSanitizer::clean($descriptionRaw);
        $locale = (string)($data['locale'] ?? '');
        if (!in_array($locale, available_locales(), true)) {
            $locale = (string)App::make('settings')->get('default_locale', 'en');
            if (!in_array($locale, available_locales(), true)) $locale = 'en';
        }
        $fields = $this->normaliseFields($data['fields'] ?? []);
        $rawFooter = is_array($data['footer'] ?? null) ? $data['footer'] : [];
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
                'title'       => $title,
                'description' => $description,
                'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                'footer_json' => json_encode($footer, JSON_UNESCAPED_UNICODE),
                'locale'      => $locale,
            ]);
            \App\App::make('activity')->log('form.updated', (int)$this->user['id'], null, null,
                "updated form '$title'", ['form_id' => $id]);
            \App\App::make('events')->fire('form.updated', [
                'form_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
            ]);
            Response::json(['ok' => true, 'id' => $id]);
            return;
        }

        $id = $this->forms->create($title, $description !== '' ? $description : null, $fields, $footer, (int)$this->user['id'], $locale);
        \App\App::make('activity')->log('form.created', (int)$this->user['id'], null, null,
            "created form '$title'", ['form_id' => $id]);
        \App\App::make('events')->fire('form.created', [
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
        \App\App::make('activity')->log('form.deleted', (int)$this->user['id'], null, null,
            "deleted form '$title'", ['form_id' => $id]);
        \App\App::make('events')->fire('form.deleted', [
            'form_id' => $id, 'title' => $title, 'actor_name' => $this->user['name'],
        ]);
        Response::json(['ok' => true]);
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
