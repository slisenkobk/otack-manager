<?php
declare(strict_types=1);
namespace App\Controller;

use App\App;
use App\Http\Request;
use App\Http\Response;
use App\Repository\AttachmentRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\TaskRepository;
use App\Repository\ProjectRepository;
use App\Service\FileUploader;

final class AttachmentController extends BaseController
{
    private AttachmentRepository     $repo;
    private FileUploader             $uploader;
    private ProjectMemberRepository  $members;
    private TaskRepository           $tasks;
    private ProjectRepository        $projects;

    public function __construct($view, $user = null)
    {
        parent::__construct($view, $user);
        $this->repo     = App::make('attachments');
        $this->uploader = App::make('uploader');
        $this->members  = App::make('members');
        $this->tasks    = App::make('tasks');
        $this->projects = App::make('projects');
    }

    private function assertMembership(string $entityType, int $entityId): void
    {
        if ($this->user['role'] === 'admin') return;

        if ($entityType === 'project') {
            if (!$this->projects->findById($entityId)) {
                Response::json(['error' => 'Not found'], 404); exit;
            }
            if (!$this->members->isMember($entityId, (int)$this->user['id'])) {
                Response::json(['error' => 'Forbidden'], 403); exit;
            }
            return;
        }

        if ($entityType === 'task') {
            $t = $this->tasks->findById($entityId);
            if (!$t) {
                Response::json(['error' => 'Not found'], 404); exit;
            }
            if (!$this->members->isMember((int)$t['project_id'], (int)$this->user['id'])) {
                Response::json(['error' => 'Forbidden'], 403); exit;
            }
            return;
        }

        if ($entityType === 'comment') {
            $stmt = App::make('db')->prepare('SELECT * FROM comments WHERE id = ?');
            $stmt->execute([$entityId]);
            $c = $stmt->fetch();
            if (!$c) {
                Response::json(['error' => 'Comment not found'], 404); exit;
            }
            if ($c['entity_type'] === 'project') {
                $projectId = (int)$c['entity_id'];
            } elseif ($c['entity_type'] === 'task') {
                $task = $this->tasks->findById((int)$c['entity_id']);
                $projectId = $task ? (int)$task['project_id'] : 0;
            } else {
                Response::json(['error' => 'Invalid parent entity'], 422); exit;
            }
            if (!$projectId || !$this->members->isMember($projectId, (int)$this->user['id'])) {
                Response::json(['error' => 'Forbidden'], 403); exit;
            }
            return;
        }

        Response::json(['error' => 'Invalid entity_type'], 422); exit;
    }

    public function upload(Request $req, array $params = []): void
    {
        $entityType = $req->post['entity_type'] ?? '';
        $entityId   = (int)($req->post['entity_id'] ?? 0);

        if (!in_array($entityType, ['project', 'task', 'comment'], true) || !$entityId) {
            Response::json(['error' => 'Invalid input'], 422); return;
        }

        $this->assertMembership($entityType, $entityId);

        $file = $req->files['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'No file'], 422); return;
        }

        $err = $this->uploader->validate($file);
        if ($err) {
            Response::json(['error' => $err], 422); return;
        }

        try {
            $stored = $this->uploader->store($file);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 422); return;
        }

        $id  = $this->repo->create($stored + [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'uploaded_by' => (int)$this->user['id'],
        ]);
        $row = $this->repo->findById($id);

        Response::json(['ok' => true, 'attachment' => [
            'id'            => (int)$row['id'],
            'filename'      => $row['filename'],
            'url'           => '/' . $row['filename'],
            'original_name' => $row['original_name'],
            'mime'          => $row['mime'],
            'size'          => (int)$row['size'],
            'is_image'      => (bool)$row['is_image'],
            'uploaded_by'   => (int)$row['uploaded_by'],
            'can_delete'    => true,
        ]]);
    }

    public function delete(Request $req, array $params): void
    {
        $id = (int)$params['id'];
        $a  = $this->repo->findById($id);
        if (!$a) {
            Response::json(['error' => 'Not found'], 404); return;
        }

        $isAdmin    = $this->user['role'] === 'admin';
        $isUploader = (int)$a['uploaded_by'] === (int)$this->user['id'];
        if (!$isAdmin && !$isUploader) {
            Response::json(['error' => 'Forbidden'], 403); return;
        }

        // Unlink from disk
        $abs = APP_ROOT . '/public/' . $a['filename'];
        if (is_file($abs)) {
            @unlink($abs);
        }

        $this->repo->delete($id);
        Response::json(['ok' => true]);
    }
}
