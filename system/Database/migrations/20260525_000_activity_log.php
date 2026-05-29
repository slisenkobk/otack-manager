<?php
declare(strict_types=1);

return function (\PDO $pdo) {
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
};
