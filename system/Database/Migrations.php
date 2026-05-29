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

        $boot->ensure('projects_color', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(projects)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'color') { $has = true; break; } }
            if (!$has) {
                $pdo->query("ALTER TABLE projects ADD COLUMN color TEXT NOT NULL DEFAULT '#1A1612'");
            }
        });

        $boot->ensure('projects_color_reseed', 1, function (\PDO $pdo) {
            // Reseed any project still on the dark default to a random palette colour
            $palette = \App\Repository\ProjectRepository::PALETTE;
            $stmt = $pdo->query("SELECT id FROM projects WHERE color = '#1A1612' OR color IS NULL");
            $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            $upd = $pdo->prepare('UPDATE projects SET color = ? WHERE id = ?');
            foreach ($ids as $id) {
                $upd->execute([$palette[array_rand($palette)], (int)$id]);
            }
        });

        $boot->ensure('tasks_sub_status', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'sub_status') { $has = true; break; } }
            if (!$has) {
                $pdo->query("ALTER TABLE tasks ADD COLUMN sub_status TEXT");
            }
        });

        $boot->ensure('tasks_priority', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(tasks)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'priority') { $has = true; break; } }
            if (!$has) {
                $pdo->query("ALTER TABLE tasks ADD COLUMN priority TEXT NOT NULL DEFAULT 'none'");
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

        $boot->ensure('users_avatar', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'avatar') { $has = true; break; } }
            if (!$has) {
                $pdo->exec('ALTER TABLE users ADD COLUMN avatar TEXT');
            }
        });

        $boot->ensure('comments_parent_id', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(comments)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'parent_id') { $has = true; break; } }
            if (!$has) {
                $pdo->exec('ALTER TABLE comments ADD COLUMN parent_id INTEGER NULL');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_comments_parent ON comments(parent_id)');
            }
        });

        // Roles upgrade: existing 'member' rows mapped to 'employee'. New role
        // 'manager' is introduced and accepted by UserController going forward.
        $boot->ensure('users_role_employee_manager', 1, function (\PDO $pdo) {
            $pdo->exec("UPDATE users SET role = 'employee' WHERE role = 'member'");
        });

        $boot->ensure('settings', 1, function (\PDO $pdo) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL DEFAULT ""
                )'
            );
        });

        $boot->ensure('forms', 1, function (\PDO $pdo) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS forms (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    hash TEXT NOT NULL UNIQUE,
                    title TEXT NOT NULL,
                    description TEXT,
                    fields_json TEXT NOT NULL DEFAULT "[]",
                    footer_json TEXT NOT NULL DEFAULT "{}",
                    status TEXT NOT NULL DEFAULT "published",
                    created_by INTEGER NOT NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_forms_status ON forms(status)');
        });

        $boot->ensure('form_submissions', 1, function (\PDO $pdo) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS form_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    form_id INTEGER NOT NULL,
                    data_json TEXT NOT NULL DEFAULT "{}",
                    footer_json TEXT NOT NULL DEFAULT "{}",
                    status TEXT NOT NULL DEFAULT "new",
                    converted_task_id INTEGER NULL,
                    converted_project_id INTEGER NULL,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )'
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form ON form_submissions(form_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_status ON form_submissions(status)');
        });

        $boot->ensure('projects_pinned_at', 1, function (\PDO $pdo) {
            $cols = $pdo->query('PRAGMA table_info(projects)')->fetchAll(\PDO::FETCH_ASSOC);
            $has = false;
            foreach ($cols as $c) { if ($c['name'] === 'pinned_at') { $has = true; break; } }
            if (!$has) {
                $pdo->exec('ALTER TABLE projects ADD COLUMN pinned_at TEXT NULL');
            }
        });

        $boot->ensure('task_links', 1, function (\PDO $pdo) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS task_links (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id INTEGER NOT NULL,
                    linked_task_id INTEGER NOT NULL,
                    created_by INTEGER NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE(task_id, linked_task_id)
                )"
            );
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_links_task ON task_links(task_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_task_links_linked ON task_links(linked_task_id)');
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
