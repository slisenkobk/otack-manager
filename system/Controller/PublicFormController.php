<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\SettingsRepository;

/**
 * Public, unauthenticated form rendering / submission. No CSRF token —
 * routes are whitelisted in public/index.php to skip CSRF.
 */
final class PublicFormController extends BaseController
{
    private FormRepository           $forms;
    private FormSubmissionRepository $subs;
    private SettingsRepository       $settings;

    public function __construct($view, $user = null) {
        parent::__construct($view, $user);
        $this->forms    = App::make('forms');
        $this->subs     = App::make('form_submissions');
        $this->settings = App::make('settings');
    }

    public function show(Request $req, array $params): void {
        $hash = (string)($params['hash'] ?? '');
        $form = $this->forms->findByHash($hash);
        if (!$form || $form['status'] === 'archived') {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $fields  = $this->decodeFields($form);
        $contact = $this->resolveContactBlock($form);
        Response::html($this->view->render('public/form', [
            'form'    => $form,
            'fields'  => $fields,
            'contact' => $contact,
            'sent'    => false,
        ], null));
    }

    public function submit(Request $req, array $params): void {
        $hash = (string)($params['hash'] ?? '');
        $form = $this->forms->findByHash($hash);
        if (!$form || $form['status'] === 'archived') {
            Response::html($this->view->render('public/form-not-found', [], null));
            return;
        }
        $fields = $this->decodeFields($form);
        $data   = [];
        $errors = [];
        foreach ($fields as $f) {
            $raw = $req->post[$f['key']] ?? null;
            if ($f['type'] === 'checkbox') {
                $raw = is_array($raw) ? array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== '')) : [];
            } else {
                $raw = is_string($raw) ? trim($raw) : '';
            }
            $isEmpty = ($f['type'] === 'checkbox') ? empty($raw) : ($raw === '');
            if (!empty($f['required']) && $isEmpty) {
                $errors[$f['key']] = 'Required';
            }
            if (!$isEmpty && $f['type'] === 'email' && !filter_var((string)$raw, FILTER_VALIDATE_EMAIL)) {
                $errors[$f['key']] = 'Invalid email';
            }
            $data[$f['key']] = $raw;
        }

        if ($errors) {
            $contact = $this->resolveContactBlock($form);
            Response::html($this->view->render('public/form', [
                'form'       => $form,
                'fields'     => $fields,
                'contact'    => $contact,
                'errors'     => $errors,
                'values'     => $data,
                'sent'       => false,
            ], null));
            return;
        }

        // Submission no longer carries user-edited footer data — the contact
        // block on the public form is read-only display only.
        $this->subs->create((int)$form['id'], $data, []);

        Response::html($this->view->render('public/form-thanks', [
            'form' => $form,
        ], null));
    }

    private function decodeFields(array $form): array {
        $fields = json_decode((string)$form['fields_json'], true);
        return is_array($fields) ? $fields : [];
    }

    private function decodeFooter(array $form): array {
        $footer = json_decode((string)$form['footer_json'], true);
        return is_array($footer) ? $footer : [];
    }

    /**
     * Returns a normalised list of contact lines to render on the public form
     * footer. Per-form overrides win over the global Settings values.
     *
     * Shape: [ ['label' => '…', 'value' => '…', 'kind' => 'company|email|phone|address|note'], ... ]
     */
    private function resolveContactBlock(array $form): array {
        $footer  = $this->decodeFooter($form);
        $globals = $this->settings->getMany(
            ['contact_company_name', 'contact_email', 'contact_phone', 'contact_address', 'contact_default_text'],
            []
        );
        $pick = function (string $key, string $globalKey) use ($footer, $globals): string {
            $local = trim((string)($footer[$key] ?? ''));
            if ($local !== '') return $local;
            return trim((string)($globals[$globalKey] ?? ''));
        };
        $rows = [];
        if (!empty($footer['show_company'])) {
            $v = $pick('company_name', 'contact_company_name');
            if ($v !== '') $rows[] = ['label' => 'Company', 'value' => $v, 'kind' => 'company'];
        }
        if (!empty($footer['show_email'])) {
            $v = $pick('email', 'contact_email');
            if ($v !== '') $rows[] = ['label' => 'Email', 'value' => $v, 'kind' => 'email'];
        }
        if (!empty($footer['show_phone'])) {
            $v = $pick('phone', 'contact_phone');
            if ($v !== '') $rows[] = ['label' => 'Phone', 'value' => $v, 'kind' => 'phone'];
        }
        if (!empty($footer['show_address'])) {
            $v = $pick('address', 'contact_address');
            if ($v !== '') $rows[] = ['label' => 'Address', 'value' => $v, 'kind' => 'address'];
        }
        if (!empty($footer['show_note'])) {
            $v = $pick('note', 'contact_default_text');
            if ($v !== '') $rows[] = ['label' => 'Note', 'value' => $v, 'kind' => 'note'];
        }
        return $rows;
    }
}
