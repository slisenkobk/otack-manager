<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    $pdo->query("CREATE TABLE project_tag_map (
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
        PRIMARY KEY(project_id, tag_id)
    )");
};
