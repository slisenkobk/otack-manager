<?php
declare(strict_types=1);
namespace App\Repository;

final class ProjectMemberRepository {
    public function __construct(private \PDO $pdo) {}

    public function add(int $projectId, int $userId, string $role = 'member'): void {
        $verb = \App\Database\Connection::driverFor($this->pdo)
            ?->insertIgnoreVerb()
            ?? throw new \RuntimeException('ProjectMemberRepository: no driver bound to PDO');
        $this->pdo->prepare(
            "$verb INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)"
        )->execute([$projectId, $userId, $role]);
    }

    public function remove(int $projectId, int $userId): void {
        $this->pdo->prepare(
            'DELETE FROM project_members WHERE project_id = ? AND user_id = ?'
        )->execute([$projectId, $userId]);
    }

    public function list(int $projectId): array {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.avatar, pm.role
             FROM project_members pm
             INNER JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?
             ORDER BY pm.role DESC, u.name ASC'
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll();
    }

    public function isMember(int $projectId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?'
        );
        $stmt->execute([$projectId, $userId]);
        return (bool)$stmt->fetch();
    }

    public function isOwner(int $projectId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND role = 'owner'"
        );
        $stmt->execute([$projectId, $userId]);
        return (bool)$stmt->fetch();
    }
}
