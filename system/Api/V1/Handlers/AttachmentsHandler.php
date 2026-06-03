<?php
declare(strict_types=1);
namespace App\Api\V1\Handlers;

use App\Api\V1\ApiResponse;
use App\Http\Request;
use App\Service\RolePolicy;

/**
 * AttachmentsHandler — the ONLY endpoint in /api/v1 that consumes
 * multipart/form-data. Every other handler reads JSON via readBody().
 *
 * For the upload action, PHP's SAPI already parses multipart automatically
 * into $_FILES and $_POST when Content-Type is multipart/form-data, so the
 * handler reads directly from those superglobals. The kernel does NOT need
 * to be modified — JsonRequest is simply bypassed for this one action.
 */
final class AttachmentsHandler extends BaseHandler
{
    /** GET /api/v1/tasks/{id}/attachments */
    public function indexForTask(Request $req, array $params = []): array
    {
        $taskId = (int)($params['id'] ?? 0);
        $task = $this->svc('tasks')->findById($taskId);
        if (!$task) return $this->notFound();
        $project = $this->svc('projects')->findById((int)$task['project_id']);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        $items = $this->svc('attachments')->listFor('task', $taskId);
        return ApiResponse::ok([
            'items' => array_map([$this, 'serialize'], $items),
        ]);
    }

    /** GET /api/v1/projects/{id}/attachments */
    public function indexForProject(Request $req, array $params = []): array
    {
        $projectId = (int)($params['id'] ?? 0);
        $project = $this->svc('projects')->findById($projectId);
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        $items = $this->svc('attachments')->listFor('project', $projectId);
        return ApiResponse::ok([
            'items' => array_map([$this, 'serialize'], $items),
        ]);
    }

    /**
     * POST /api/v1/attachments (multipart/form-data)
     *
     * Multipart exception — this is the only handler action that does not
     * read JSON. Fields are read straight from $_POST/$_FILES, populated by
     * PHP's SAPI from the multipart body. Everything else in the API stays
     * JSON-only.
     */
    public function create(Request $req, array $params = []): array
    {
        $entity   = (string)($_POST['entity'] ?? '');
        $entityId = (int)($_POST['entity_id'] ?? 0);
        $file     = $_FILES['file'] ?? null;

        if (!in_array($entity, ['task', 'project'], true)) {
            return ApiResponse::error(422, 'validation_failed', 'entity must be "task" or "project"', ['entity' => 'invalid']);
        }
        if ($entityId <= 0) {
            return ApiResponse::error(422, 'validation_failed', 'entity_id is required', ['entity_id' => 'required']);
        }
        if (!$file || !is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ApiResponse::error(422, 'validation_failed', 'file is required', ['file' => 'required']);
        }

        // Resolve project context for visibility + edit check.
        if ($entity === 'task') {
            $task = $this->svc('tasks')->findById($entityId);
            if (!$task) return $this->notFound();
            $project = $this->svc('projects')->findById((int)$task['project_id']);
            if (!$project || !$this->canSeeProject($project)) return $this->notFound();
            if (!RolePolicy::canEditTask($this->user(), $task, $this->svc('members'))) {
                return $this->forbidden();
            }
        } else {
            $project = $this->svc('projects')->findById($entityId);
            if (!$project || !$this->canSeeProject($project)) return $this->notFound();
            if (!RolePolicy::canEditProject($this->user(), $project, $this->svc('members'))) {
                return $this->forbidden();
            }
        }

        $uploader = $this->svc('uploader');
        if ($err = $uploader->validate($file)) {
            return ApiResponse::error(422, 'validation_failed', $err, ['file' => 'invalid']);
        }

        try {
            $stored = $uploader->store($file);
        } catch (\Throwable $e) {
            return ApiResponse::error(422, 'validation_failed', $e->getMessage(), ['file' => 'invalid']);
        }

        $id = $this->svc('attachments')->create($stored + [
            'entity_type' => $entity,
            'entity_id'   => $entityId,
            'uploaded_by' => $this->userId(),
        ]);
        $row = $this->svc('attachments')->findById($id);
        return ApiResponse::created($this->serialize($row));
    }

    /**
     * DELETE /api/v1/attachments/{id}
     *
     * Auth mirrors AttachmentController::delete: visibility (project
     * membership / admin) AND (admin OR original uploader). canEditTask /
     * canEditProject are NOT applied here — the web controller intentionally
     * allows any uploader (incl. employees) to delete their own files.
     */
    public function destroy(Request $req, array $params = []): array
    {
        $id = (int)($params['id'] ?? 0);
        $row = $this->svc('attachments')->findById($id);
        if (!$row) return $this->notFound();

        $entityType = (string)$row['entity_type'];
        $entityId   = (int)$row['entity_id'];

        // Visibility: must be able to see the parent project.
        if ($entityType === 'task') {
            $task = $this->svc('tasks')->findById($entityId);
            $project = $task ? $this->svc('projects')->findById((int)$task['project_id']) : null;
        } elseif ($entityType === 'project') {
            $project = $this->svc('projects')->findById($entityId);
        } else {
            // 'comment' or anything else — not surfaced through this API yet.
            return $this->notFound();
        }
        if (!$project || !$this->canSeeProject($project)) return $this->notFound();

        $isAdmin    = RolePolicy::isAdmin($this->user());
        $isUploader = (int)$row['uploaded_by'] === $this->userId();
        if (!$isAdmin && !$isUploader) {
            return $this->forbidden();
        }

        // Unlink from disk (best-effort, same as web controller).
        $abs = APP_ROOT . '/public/' . $row['filename'];
        if (is_file($abs)) {
            @unlink($abs);
        }

        $this->svc('attachments')->delete($id);
        return ApiResponse::noContent();
    }

    private function serialize(array $a): array
    {
        return [
            'id'            => (int)$a['id'],
            'entity_type'   => (string)$a['entity_type'],
            'entity_id'     => (int)$a['entity_id'],
            'filename'      => (string)$a['filename'],
            'path'          => '/' . ltrim((string)$a['filename'], '/'),
            'original_name' => (string)$a['original_name'],
            'mime'          => (string)$a['mime'],
            'size'          => (int)$a['size'],
            'is_image'      => (bool)$a['is_image'],
            'uploaded_by'   => (int)$a['uploaded_by'],
            'created_at'    => $a['created_at'] ?? null,
        ];
    }
}
