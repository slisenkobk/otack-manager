<?php
declare(strict_types=1);

return function (\PDO $pdo) {
    // Reseed any project still on the dark default to a random palette colour
    $palette = \App\Repository\ProjectRepository::PALETTE;
    $stmt = $pdo->query("SELECT id FROM projects WHERE color = '#1A1612' OR color IS NULL");
    $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    $upd = $pdo->prepare('UPDATE projects SET color = ? WHERE id = ?');
    foreach ($ids as $id) {
        $upd->execute([$palette[array_rand($palette)], (int)$id]);
    }
};
