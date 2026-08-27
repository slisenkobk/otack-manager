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
        $userId = (int)$user['id'];
        $isAuthor = (int)($task['created_by'] ?? 0) === $userId;
        if ($isAuthor) return true;
        // The assignee owns execution of their own task: they may move it
        // between columns and update its fields. Without this an employee
        // cannot progress work a manager handed them — which also blocks
        // API service accounts (see docs/API.md §2.1). Guard against the
        // null→0 cast: an unassigned task must not grant anyone rights.
        $assigneeId = (int)($task['assignee_id'] ?? 0);
        if ($assigneeId !== 0 && $assigneeId === $userId) return true;
        // manager can edit any task within projects they own
        if (self::isManager($user)) {
            return $members->isOwner((int)$task['project_id'], $userId);
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

    /** Polls CRUD — same scope as Forms (admin + manager). */
    public static function canManagePolls(array $user): bool {
        return self::isAdmin($user) || self::isManager($user);
    }

    /** Can delete a comment: admins always; otherwise only the original author. */
    public static function canDeleteComment(array $user, array $comment): bool
    {
        if (self::isAdmin($user)) return true;
        return (int)($comment['user_id'] ?? 0) === (int)($user['id'] ?? 0);
    }
}
