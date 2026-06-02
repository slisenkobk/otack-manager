<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('activity_log', function (Blueprint $t) {
        $t->id();
        $t->string('event', 64);
        $t->bigInteger('actor_id');
        $t->bigInteger('project_id')->nullable();
        $t->bigInteger('task_id')->nullable();
        $t->string('summary', 500);
        $t->text('meta')->nullable();
        $t->timestamp('created_at');
        $t->index(['created_at'])->name('activity_log_created');
        $t->index(['project_id', 'created_at'])->name('activity_log_project');
        $t->foreign('actor_id')->references('id')->on('users');
        $t->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
        $t->foreign('task_id')->references('id')->on('tasks')->onDelete('SET NULL');
    });

    // Backfill from existing comments so the feed has history on day one.
    $pdo = $schema->pdo();
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
