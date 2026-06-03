<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Service\RolePolicy;
use App\Http\Request;

/**
 * Read-only Forms surface. Mirrors FormDataController gating:
 *   - canViewFormsData → admin + manager only
 *   - non-admins only see forms they created (and their submissions)
 */
final class FormsHandler extends BaseHandler
{
    /** GET /api/v1/forms */
    public function index(Request $req): array
    {
        if (!RolePolicy::canViewFormsData($this->user())) return $this->forbidden();

        $query = trim((string)($req->query['q'] ?? ''));
        $rows  = $this->svc('forms')->listAll($query);

        // Manager scope: only own forms. Admin sees everything.
        if (!RolePolicy::isAdmin($this->user())) {
            $uid  = $this->userId();
            $rows = array_values(array_filter($rows, fn($f) => (int)$f['created_by'] === $uid));
        }

        return ApiResponse::ok(['items' => array_map([$this, 'serializeForm'], $rows)]);
    }

    /** GET /api/v1/forms/{id} */
    public function show(Request $req): array
    {
        if (!RolePolicy::canViewFormsData($this->user())) return $this->forbidden();

        $id   = $this->pathId($req, 3);
        $form = $this->svc('forms')->findById($id);
        if (!$form) return $this->notFound();
        if (!$this->canSeeForm($form)) return $this->notFound();

        return ApiResponse::ok($this->serializeFormFull($form));
    }

    /** GET /api/v1/forms/{id}/submissions */
    public function submissions(Request $req): array
    {
        if (!RolePolicy::canViewFormsData($this->user())) return $this->forbidden();

        $id   = $this->pathId($req, 3);
        $form = $this->svc('forms')->findById($id);
        if (!$form) return $this->notFound();
        if (!$this->canSeeForm($form)) return $this->notFound();

        $status = (string)($req->query['status'] ?? '');
        $status = $status !== '' ? $status : null;
        $after  = isset($req->query['after']) ? (int)$req->query['after'] : 0;
        $limit  = min(100, max(1, (int)($req->query['limit'] ?? 50)));

        $rows = $this->svc('form_submissions')->listAll($id, $status, '');
        // listAll returns ORDER BY created_at DESC. For cursor pagination by id
        // descending, "after" means "give me ids less than this cursor".
        if ($after > 0) {
            $rows = array_values(array_filter($rows, fn($s) => (int)$s['id'] < $after));
        }
        $slice = array_slice($rows, 0, $limit + 1);
        $next  = null;
        if (count($slice) > $limit) {
            $slice = array_slice($slice, 0, $limit);
            $next  = (int)end($slice)['id'];
        }
        return ApiResponse::paginated(
            array_map([$this, 'serializeSubmissionListItem'], $slice),
            $next
        );
    }

    /** GET /api/v1/submissions/{id} */
    public function showSubmission(Request $req): array
    {
        if (!RolePolicy::canViewFormsData($this->user())) return $this->forbidden();

        $id  = $this->pathId($req, 3);
        $sub = $this->svc('form_submissions')->findById($id);
        if (!$sub) return $this->notFound();
        $form = $this->svc('forms')->findById((int)$sub['form_id']);
        if (!$form) return $this->notFound();
        if (!$this->canSeeForm($form)) return $this->notFound();

        return ApiResponse::ok($this->serializeSubmissionFull($sub, $form));
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** Admin sees any form; manager sees only their own. */
    private function canSeeForm(array $form): bool
    {
        if (RolePolicy::isAdmin($this->user())) return true;
        return (int)$form['created_by'] === $this->userId();
    }

    /** List-row shape: minimal metadata, no field definitions. */
    private function serializeForm(array $f): array
    {
        return [
            'id'          => (int)$f['id'],
            'hash'        => (string)$f['hash'],
            'title'       => (string)$f['title'],
            'description' => $f['description'] ?? null,
            'status'      => (string)($f['status'] ?? 'published'),
            'locale'      => (string)($f['locale'] ?? 'en'),
            'project_id'  => isset($f['project_id']) && $f['project_id'] !== null ? (int)$f['project_id'] : null,
            'created_by'  => (int)$f['created_by'],
            'created_at'  => $f['created_at'] ?? null,
            'updated_at'  => $f['updated_at'] ?? null,
        ];
    }

    /** Detail shape: list metadata + parsed fields[] + footer. */
    private function serializeFormFull(array $f): array
    {
        $base = $this->serializeForm($f);
        $base['fields'] = json_decode((string)($f['fields_json'] ?? '[]'), true) ?: [];
        $base['footer'] = json_decode((string)($f['footer_json'] ?? '{}'), true) ?: [];
        $base['auto_create_task']    = !empty($f['auto_create_task']);
        $base['task_title_template'] = $f['task_title_template'] ?? null;
        $base['success_message']     = $f['success_message'] ?? null;
        return $base;
    }

    /** Submission list row — light shape (no answer blob). */
    private function serializeSubmissionListItem(array $s): array
    {
        return [
            'id'         => (int)$s['id'],
            'form_id'    => (int)$s['form_id'],
            'status'     => (string)$s['status'],
            'created_at' => $s['created_at'] ?? null,
            'updated_at' => $s['updated_at'] ?? null,
            'form_title' => $s['form_title'] ?? null,
        ];
    }

    /** Single submission with parsed answers + form snapshot. */
    private function serializeSubmissionFull(array $s, array $form): array
    {
        return [
            'id'                   => (int)$s['id'],
            'form_id'              => (int)$s['form_id'],
            'status'               => (string)$s['status'],
            'answers'              => json_decode((string)($s['data_json']   ?? '{}'), true) ?: [],
            'footer'               => json_decode((string)($s['footer_json'] ?? '{}'), true) ?: [],
            'remote_ip'            => $s['remote_ip'] ?? null,
            'converted_task_id'    => isset($s['converted_task_id'])    && $s['converted_task_id']    !== null ? (int)$s['converted_task_id']    : null,
            'converted_project_id' => isset($s['converted_project_id']) && $s['converted_project_id'] !== null ? (int)$s['converted_project_id'] : null,
            'created_at'           => $s['created_at'] ?? null,
            'updated_at'           => $s['updated_at'] ?? null,
            'form'                 => [
                'id'     => (int)$form['id'],
                'title'  => (string)$form['title'],
                'fields' => json_decode((string)($form['fields_json'] ?? '[]'), true) ?: [],
            ],
        ];
    }
}
