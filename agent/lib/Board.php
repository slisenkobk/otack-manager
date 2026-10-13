<?php
declare(strict_types=1);

namespace App\Agent;

/** Read helpers over one project's board, shared by `otack todo` and the Telegram digest. */
final class Board
{
    public static function project(Client $c, int $pid): array
    {
        $r = $c->get("/projects/$pid");
        if ($r['status'] !== 200) {
            throw new \RuntimeException("GET /projects/$pid returned {$r['status']}");
        }
        if (!is_array($r['json']) || !is_array($r['json']['columns'] ?? null)) {
            throw new \RuntimeException("GET /projects/$pid: response is missing a 'columns' array");
        }
        return $r['json'];
    }

    /** @return list<int> */
    public static function workableColumnIds(array $project): array
    {
        $ids = [];
        foreach ($project['columns'] ?? [] as $col) {
            if (empty($col['is_done']) && empty($col['is_backlog'])) {
                $ids[] = (int)$col['id'];
            }
        }
        return $ids;
    }

    /** @return list<array> */
    public static function openTasks(Client $c, array $project): array
    {
        $cols = self::workableColumnIds($project);
        $rows = $c->getAll('/projects/' . (int)$project['id'] . '/tasks', ['limit' => 100]);
        return array_values(array_filter($rows, fn($t) => in_array((int)($t['column_id'] ?? 0), $cols, true)));
    }

    /** @return array<int,true> */
    public static function criticalTaskIds(Client $c, int $pid, array $tagNames): array
    {
        if ($tagNames === []) { return []; }
        $want = array_map(fn($n) => mb_strtolower((string)$n), $tagNames);
        $r = $c->get("/projects/$pid/tags");
        if ($r['status'] !== 200) {
            throw new \RuntimeException("GET /projects/$pid/tags returned {$r['status']}");
        }
        $ids = [];
        foreach (($r['json']['items'] ?? []) as $tag) {
            // A tag without an id would query tag_id=0 — skip it.
            if (!is_array($tag) || (int)($tag['id'] ?? 0) <= 0) { continue; }
            if (!in_array(mb_strtolower((string)($tag['name'] ?? '')), $want, true)) { continue; }
            foreach ($c->getAll("/projects/$pid/tasks", ['tag_id' => (int)$tag['id'], 'limit' => 100]) as $t) {
                if ((int)($t['id'] ?? 0) > 0) { $ids[(int)$t['id']] = true; }
            }
        }
        return $ids;
    }
}
