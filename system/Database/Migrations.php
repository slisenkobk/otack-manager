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

        $boot->ensure('activity_log', 1, function (\PDO $pdo) {
            $pdo->query("CREATE TABLE activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event TEXT NOT NULL,
                actor_id INTEGER NOT NULL REFERENCES users(id),
                project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
                task_id INTEGER REFERENCES tasks(id) ON DELETE SET NULL,
                summary TEXT NOT NULL,
                meta TEXT,
                created_at TEXT NOT NULL
            )");
            $pdo->query("CREATE INDEX activity_log_created ON activity_log(created_at DESC)");
            $pdo->query("CREATE INDEX activity_log_project ON activity_log(project_id, created_at DESC)");

            // Backfill from existing comments so the feed has history on day one.
            $stmt = $pdo->query(
                "SELECT c.id, c.entity_type, c.entity_id, c.user_id, c.body, c.created_at
                 FROM comments c ORDER BY c.id"
            );
            $insert = $pdo->prepare(
                "INSERT INTO activity_log (event, actor_id, project_id, task_id, summary, meta, created_at)
                 VALUES ('comment.created', ?, ?, ?, ?, ?, ?)"
            );
            $taskLookup = $pdo->prepare('SELECT project_id FROM tasks WHERE id = ?');
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $projectId = null; $taskId = null;
                if ($c['entity_type'] === 'project') { $projectId = (int)$c['entity_id']; }
                elseif ($c['entity_type'] === 'task') {
                    $taskId = (int)$c['entity_id'];
                    $taskLookup->execute([$taskId]);
                    $row = $taskLookup->fetch(\PDO::FETCH_ASSOC);
                    if ($row) { $projectId = (int)$row['project_id']; }
                }
                $summary = mb_substr(strip_tags((string)$c['body']), 0, 200);
                $insert->execute([
                    (int)$c['user_id'], $projectId, $taskId,
                    $summary, json_encode(['comment_id' => (int)$c['id']]),
                    (string)$c['created_at'],
                ]);
            }
        });

        $boot->ensure('task_columns_backlog', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(task_columns)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'is_backlog') { $has = true; break; } }
            if (!$has) {
                $pdo->exec('ALTER TABLE task_columns ADD COLUMN is_backlog INTEGER NOT NULL DEFAULT 0');
            }
            $projects = $pdo->query('SELECT id FROM projects')->fetchAll(\PDO::FETCH_COLUMN);
            $check = $pdo->prepare('SELECT 1 FROM task_columns WHERE project_id = ? AND is_backlog = 1');
            $shift = $pdo->prepare('UPDATE task_columns SET position = position + 1 WHERE project_id = ?');
            $insert = $pdo->prepare(
                "INSERT INTO task_columns (project_id, name, color, position, is_done, is_backlog)
                 VALUES (?, 'Backlog', '#8B7C68', 0, 0, 1)"
            );
            foreach ($projects as $pid) {
                $check->execute([(int)$pid]);
                if ($check->fetchColumn()) continue;
                $shift->execute([(int)$pid]);
                $insert->execute([(int)$pid]);
            }
        });

        if (\App\App::env('SEED_DEFAULT_ADMIN_EMAIL') !== '') {
            $boot->ensure('seed_default_admin', 1, function (\PDO $pdo) {
                $email = \App\App::env('SEED_DEFAULT_ADMIN_EMAIL');
                $hash  = \App\App::env('SEED_DEFAULT_ADMIN_PASSWORD_HASH');
                $name  = \App\App::env('SEED_DEFAULT_ADMIN_NAME', 'Admin');
                if ($hash === '') return;
                $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetchColumn()) return;
                $pdo->prepare(
                    "INSERT INTO users (email, password_hash, name, role, status, created_at)
                     VALUES (?, ?, ?, 'admin', 'approved', datetime('now'))"
                )->execute([$email, $hash, $name]);
            });
        }
    }
}
