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
     * `$selfUserId` must be a positive integer. `0` is not treated as "no
     * self id yet" — a caller that ever passes `0` (e.g. a `/me` response
     * reshaped so `$me['json']['id'] ?? 0` silently degrades) would produce
     * a watermark that never matches anything, so the entire thread reads
     * as new on every tick. That failure mode is indistinguishable from the
     * legitimate "the agent has never commented" case, so it must fail loud
     * at the boundary instead of degrading into an unsupervised reply loop.
     *
     * @param list<array{id:int, user_id:int}> $comments
     * @throws \InvalidArgumentException if $selfUserId is not positive
     * @return list<array<string,mixed>>
     */
    public static function newComments(array $comments, int $selfUserId): array
    {
        if ($selfUserId <= 0) {
            throw new \InvalidArgumentException(
                "selfUserId must be a positive integer, got $selfUserId"
            );
        }

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
     * Within a rung, the lowest task id wins. Callers have no documented
     * ordering contract with this function — the only reason ties break
     * consistently today is that `TaskRepository::listAssignedAfterId()`
     * happens to `ORDER BY id ASC` (system/Repository/TaskRepository.php).
     * Sorting explicitly here means a caller that assembles the list
     * differently can never flip the winner tick to tick on identical data.
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
                usort($rows, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
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
     * `$max` starts at `0.0` and is only ever raised by `max()`, so it can
     * never end up negative — `$max + 1024.0` is correct unconditionally;
     * there is no reachable case that needs a separate empty-column branch.
     * `NAN` positions are swallowed to `1024.0` by `max()`'s own behaviour
     * and that is left alone. What is not left alone: a position already at
     * (or near) `PHP_FLOAT_MAX` would let `$max + 1024.0` round back down to
     * `$max` (a tie, not an append), and a position that is already `INF`
     * (e.g. from a value PHP could only parse as infinite) makes the sum
     * `INF` too — `json_encode(['position' => INF])` returns `false`, so the
     * move request body would silently become an empty string, and
     * `TasksHandler.php`'s `(float)($body['position'] ?? 0)` has no bounds
     * check to catch that downstream. Fail loud here instead.
     *
     * @param list<array{position:float|int|string}> $rowsInColumn tasks already in the target column
     * @throws \RuntimeException if the next position would not be a usable, strictly-greater float
     */
    public static function nextPosition(array $rowsInColumn): float
    {
        $max = 0.0;
        foreach ($rowsInColumn as $r) {
            $max = max($max, (float)$r['position']);
        }
        $next = $max + 1024.0;
        if (!is_finite($next) || $next <= $max) {
            throw new \RuntimeException(
                "nextPosition: computed position $next is not usable past max $max"
            );
        }
        return $next;
    }
}
