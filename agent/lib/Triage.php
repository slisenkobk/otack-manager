<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Pure decision logic: what is new since the agent last spoke, and which
 * task deserves this tick. Kept free of I/O so it can be tested exhaustively.
 */
final class Triage
{
    /**
     * Comments written after the agent's own most recent one (spec §4.1).
     *
     * The watermark is the agent's last comment id rather than a stored
     * cursor: the agent ends every phase with a comment, so it marks its own
     * place, and the mark survives a crash, a lost session, and a human
     * editing the board by hand.
     *
     * @param list<array{id:int, user_id:int}> $comments
     * @return list<array<string,mixed>>
     */
    public static function newComments(array $comments, int $selfUserId): array
    {
        $watermark = 0;
        foreach ($comments as $c) {
            if ((int)$c['user_id'] === $selfUserId) {
                $watermark = max($watermark, (int)$c['id']);
            }
        }
        $out = [];
        foreach ($comments as $c) {
            if ((int)$c['id'] > $watermark) { $out[] = $c; }
        }
        usort($out, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        return $out;
    }

    /**
     * Choose the task for this tick.
     *
     * Order matters and encodes the spec's §4.3 rules:
     *  1. resume  — a task already in flight; never leave one half-done
     *  2. rework  — review feedback arrived; the human is waiting on us
     *  3. approved— an approval question was answered; unblock it
     *  4. new     — nothing in flight, start something
     *
     * `awaiting_approval` with no new comments does NOT block the queue: it
     * is skipped so one unanswered question cannot idle the agent for a day.
     * `blocked` and form-created tasks are never picked (spec §10).
     *
     * `from_form` filtering here is a SECOND line of defence, not the first.
     * The field is present only on the DETAIL task shape
     * (`TasksHandler::serializeOne()`) — `GET /tasks` list items
     * (`serializeListItem()`) never carry it at all, so a task fed to `pick`
     * from a list response has nothing here to filter on and will be
     * returned regardless of its real `from_form` value. The caller
     * (Task 4's `next` command) is responsible for verifying the winner with
     * `GET /tasks/{id}` before acting on it — see that task's write-up. This
     * filter only helps when the caller already has the DETAIL shape in
     * hand (e.g. re-triaging after a `GET /tasks/{id}` round trip).
     *
     * @param list<array{id:int, agent_state:?string, from_form?:bool}> $tasks
     * @param array<int,int> $newCommentCounts
     * @return array{task:array<string,mixed>, reason:string}|null
     */
    public static function pick(array $tasks, array $newCommentCounts): ?array
    {
        $eligible = array_values(array_filter(
            $tasks,
            fn($t) => empty($t['from_form']) && ($t['agent_state'] ?? null) !== 'blocked'
        ));

        $byState = function (array $states) use ($eligible): array {
            return array_values(array_filter($eligible, fn($t) => in_array($t['agent_state'] ?? null, $states, true)));
        };
        $withComments = function (array $rows) use ($newCommentCounts): array {
            return array_values(array_filter($rows, fn($t) => ($newCommentCounts[(int)$t['id']] ?? 0) > 0));
        };

        foreach ([
            ['resume',   $byState(['researching', 'implementing'])],
            ['rework',   $withComments($byState(['review']))],
            ['approved', $withComments($byState(['awaiting_approval']))],
            ['new',      $byState([null])],
        ] as [$reason, $rows]) {
            if ($rows !== []) {
                return ['task' => $rows[0], 'reason' => $reason];
            }
        }

        return null;
    }

    /**
     * Position for a card appended to the end of a column.
     *
     * `TaskRepository::move()` writes whatever float `position` it is given,
     * unchanged. A flat constant here would collide every agent-moved card
     * at the same spot the moment the agent moves a second card into a
     * column that already holds one from an earlier tick. Appending past
     * the current maximum is the same ordering the web UI's drag-and-drop
     * relies on.
     *
     * @param list<array{position:float|int|string}> $rowsInColumn tasks already in the target column
     */
    public static function nextPosition(array $rowsInColumn): float
    {
        $max = 0.0;
        foreach ($rowsInColumn as $r) {
            $max = max($max, (float)$r['position']);
        }
        return $max > 0.0 ? $max + 1024.0 : 1024.0;
    }
}
