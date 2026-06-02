<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\ProjectMemberRepository;

/**
 * Central place for "what can role X do?" checks.
 *
 * Three global roles:
 *   admin     — full power, sees everything
 *   manager   — can create projects, manage projects they own, do task work
 *               in any project they're a member of
 *   employee  — task work only, scoped to projects they're added to; can only
 *               edit/delete tasks they themselves created (any-task fields
 *               like status, comments, attachments remain open)
 *
 * Convention: every check takes the `users` row array (`$user`) so callers
 * pass `$this->user` directly.
 */
final class RolePolicy
{
    public static function isAdmin(array $user): bool   { return ($user['role'] ?? '') === 'admin'; }
    public static function isManager(array $user): bool { return ($user['role'] ?? '') === 'manager'; }
    public static function isEmployee(array $user): bool { return ($user['role'] ?? '') === 'employee'; }

    public static function canCreateProject(array $user): bool {
        return self::isAdmin($user) || self::isManager($user);
    }

    /** Can edit/delete the project as a whole (rename, archive, delete). */
    public static function canEditProject(array $user, array $project, ProjectMemberRepository $members): bool {
        if (self::isAdmin($user)) return true;
        if (self::isEmployee($user)) return false;
        // manager: only their own projects
        return $members->isOwner((int)$project['id'], (int)$user['id']);
    }

    /** Can fully edit/delete a task (title, description, links, delete). */
    public static function canEditTask(array $user, array $task, ProjectMemberRepository $members): bool {
        if (self::isAdmin($user)) return true;
        $isAuthor = (int)($task['created_by'] ?? 0) === (int)$user['id'];
        if ($isAuthor) return true;
        // manager can edit any task within projects they own
        if (self::isManager($user)) {
            return $members->isOwner((int)$task['project_id'], (int)$user['id']);
        }
        return false;
    }

    /** Promote-to-project requires the same right as creating a project. */
    public static function canPromoteTaskToProject(array $user): bool {
        return self::canCreateProject($user);
    }

    /** Can use the Forms builder (create / edit / delete forms). */
    public static function canManageForms(array $user): bool {
        return self::isAdmin($user) || self::isManager($user);
    }

    /** Can see Forms Data section. */
    public static function canViewFormsData(array $user): bool {
        return self::isAdmin($user) || self::isManager($user);
    }

    /** Settings page is admin-only. */
    public static function canManageSettings(array $user): bool {
        return self::isAdmin($user);
    }

    /** Short-link CRUD — same scope as Forms (admin + manager). */
    public static function canManageLinks(array $user): bool {
        return self::isAdmin($user) || self::isManager($user);
    }
}
