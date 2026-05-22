<?php
declare(strict_types=1);
namespace App\Database;

final class Migrations
{
    public static function run(SchemaBootstrap $boot): void
    {
        $boot->ensure('users', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'member',
                status TEXT NOT NULL DEFAULT 'pending',
                telegram_chat_id TEXT,
                created_at TEXT NOT NULL,
                last_login_at TEXT
            )");
        });

        $boot->ensure('projects', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'active',
                created_by INTEGER NOT NULL REFERENCES users(id),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )");
        });

        $boot->ensure('project_members', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE project_members (
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role       TEXT NOT NULL DEFAULT 'member',
                PRIMARY KEY(project_id, user_id)
            )");
        });

        $boot->ensure('task_columns', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE task_columns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                name TEXT NOT NULL,
                color TEXT NOT NULL DEFAULT '#8B7C68',
                position INTEGER NOT NULL,
                is_done INTEGER NOT NULL DEFAULT 0
            )");
        });

        $boot->ensure('tasks', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                column_id INTEGER NOT NULL REFERENCES task_columns(id),
                title TEXT NOT NULL,
                description TEXT,
                position REAL NOT NULL,
                assignee_id INTEGER REFERENCES users(id),
                due_date TEXT,
                created_by INTEGER NOT NULL REFERENCES users(id),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )");
        });

        $boot->ensure('tags', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scope TEXT NOT NULL,
                name TEXT NOT NULL,
                color TEXT NOT NULL DEFAULT '#8B7C68',
                UNIQUE(scope, name)
            )");
        });

        $boot->ensure('project_tag_map', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE project_tag_map (
                project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
                tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY(project_id, tag_id)
            )");
        });

        $boot->ensure('task_tag_map', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE task_tag_map (
                task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
                tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY(task_id, tag_id)
            )");
        });

        $boot->ensure('comments', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL REFERENCES users(id),
                body TEXT NOT NULL,
                created_at TEXT NOT NULL
            )");
            $pdo->query("CREATE INDEX comments_entity_idx ON comments(entity_type, entity_id, created_at)");
        });

        $boot->ensure('attachments', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime TEXT NOT NULL,
                size INTEGER NOT NULL,
                is_image INTEGER NOT NULL DEFAULT 0,
                uploaded_by INTEGER NOT NULL REFERENCES users(id),
                created_at TEXT NOT NULL
            )");
            $pdo->query("CREATE INDEX attachments_entity_idx ON attachments(entity_type, entity_id)");
        });

        $boot->ensure('notifications_log', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE notifications_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                payload TEXT NOT NULL,
                ok INTEGER NOT NULL,
                error TEXT,
                sent_at TEXT NOT NULL
            )");
        });
    }
}
