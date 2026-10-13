<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * One Telegram selection pass, run by otack-tick before the Claude session
 * (deterministic PHP — the model never sees the Telegram token):
 *   1. collect the owner's taps since the last pass;
 *   2. walk the approved list: assign each task the bot does not own yet
 *      (only if unassigned) with an audit comment, and drop what the bot has
 *      already started or what left its reach;
 *   3. send a fresh ranked digest when one is due.
 *
 * Fail-closed: anything that goes wrong either drops an approval or throws.
 * A thrown pass approves nothing new; the caller logs it and carries on with
 * work the bot already has.
 */
final class Approval
{
    public static function run(Config $cfg, Client $client, Telegram $tg, string $statePath, \DateTimeImmutable $now): array
    {
        if ($cfg->telegram === null) {
            throw new \RuntimeException('Approval::run needs agent scope with approval.mode = telegram');
        }
        $notes = [];
        $chatId = $cfg->telegram['chat_id'];

        // A corrupt state file cannot say which taps were already applied or
        // what the owner approved. Move it aside (kept for inspection), approve
        // nothing this pass and tell the owner once; the next pass starts from
        // an empty state and sends a fresh digest. Stale taps on the old digest
        // are then answered as stale, because no digest is live.
        try {
            $state = Selection::load($statePath);
        } catch (\RuntimeException $e) {
            $aside = $statePath . '.broken-' . $now->format('YmdHis');
            if (!@rename($statePath, $aside)) {
                throw new \RuntimeException($e->getMessage() . " (and it could not be moved to $aside)");
            }
            $notes[] = basename($statePath) . " was unreadable — moved to $aside; nothing approved this pass";
            try {
                $tg->call('sendMessage', ['chat_id' => $chatId, 'disable_web_page_preview' => true,
                    'text' => 'Otack: файл выбора задач (' . basename($statePath) . ') повреждён и отложен в сторону. '
                        . 'Одобренных задач сейчас нет — новый список придёт со следующим тиком.']);
            } catch (\RuntimeException $e2) {
                $notes[] = 'sendMessage: ' . $e2->getMessage();
            }
            return ['approved' => [], 'notes' => $notes];
        }

        $me = $client->get('/me');
        $botId = (int)($me['json']['id'] ?? 0);
        if ($me['status'] !== 200 || $botId <= 0) {
            throw new \RuntimeException('GET /me failed (' . $me['status'] . ')');
        }

        // 1. Taps. The offset is persisted before any side effect, so an
        // update is never applied twice even if a later step throws.
        $updates = $tg->call('getUpdates', ['offset' => $state['offset'], 'timeout' => 0, 'allowed_updates' => ['callback_query']]);
        [$state, $acks, $newly] = Selection::applyUpdates($state, is_array($updates) ? $updates : [], $chatId);
        Selection::save($statePath, $state);
        foreach ($acks as $a) {
            if ($a['callback_id'] === '') { continue; }
            try {
                $tg->call('answerCallbackQuery', ['callback_query_id' => $a['callback_id'], 'text' => $a['text']]);
            } catch (\RuntimeException $e) {
                $notes[] = 'answerCallbackQuery: ' . $e->getMessage();
            }
        }

        // 2. Every approved id, not only the newly tapped ones: a pass that
        // died between saving a tap and the PATCH leaves an approval the bot
        // does not own yet, and `otack next` sees only the bot's own tasks.
        $keep = [];
        foreach ($state['approved'] as $id) {
            $t = $client->get("/tasks/$id");
            if ($t['status'] !== 200 || !is_array($t['json'])) { $notes[] = "#$id: GET returned {$t['status']} — dropped"; continue; }
            $task = $t['json'];
            if (($task['agent_state'] ?? null) !== null) { continue; } // started (or blocked): no longer an approval
            if (!empty($task['from_form'])) { $notes[] = "#$id came from a public form — the bot never works those; dropped"; continue; }
            if ($cfg->projectPath((int)($task['project_id'] ?? 0)) === null) { $notes[] = "#$id is in a project not listed in projects.json — dropped"; continue; }
            $assignee = $task['assignee_id'] ?? null;
            if ($assignee !== null && (int)$assignee !== $botId) { $notes[] = "#$id is assigned to someone else now — left alone"; continue; }
            if ($assignee === null) {
                $r = $client->patch("/tasks/$id", ['assignee_id' => $botId]);
                if ($r['status'] !== 200) { $notes[] = "#$id: assign returned {$r['status']} — dropped"; continue; }
            }
            if ($assignee === null || in_array($id, $newly, true)) {
                $c = $client->post('/comments', ['entity' => 'task', 'entity_id' => $id,
                    'body' => 'Владелец выбрал эту задачу для агента в Telegram. Агент начнёт с исследования и вернётся сюда с планом на согласование.']);
                if ($c['status'] !== 201) { $notes[] = "#$id: audit comment returned {$c['status']}"; }
            }
            $keep[] = $id;
        }
        $state['approved'] = $keep;
        Selection::save($statePath, $state);

        // 3. Digest (only when nothing is queued — Selection::needsDigest).
        if (Selection::needsDigest($state, $now, $cfg->approval['digest_every_hours'])) {
            [$ranked, $total, $names] = self::candidates($cfg, $client, $botId, $now);
            if ($ranked !== []) {
                $d = Selection::digest(array_slice($ranked, 0, $cfg->approval['digest_size']), $names, $total);
                $msg = $tg->call('sendMessage', [
                    'chat_id' => $chatId, 'text' => $d['text'], 'parse_mode' => 'HTML',
                    'reply_markup' => $d['reply_markup'], 'disable_web_page_preview' => true,
                ]);
                $state['digest'] = ['message_id' => (int)($msg['message_id'] ?? 0), 'sent_at' => $now->getTimestamp(),
                    'offered' => $d['offered'], 'answered' => false];
                $notes[] = 'digest sent: ' . count($d['offered']) . " of $total";
                Selection::save($statePath, $state);
            }
        }

        return ['approved' => $state['approved'], 'notes' => $notes];
    }

    /** Open, unstarted tasks in the bot's projects that are unassigned or the bot's own, ranked best first. */
    public static function candidates(Config $cfg, Client $client, int $botId, \DateTimeImmutable $now): array
    {
        $all = [];
        $names = [];
        foreach (array_keys($cfg->projects) as $pidStr) {
            $pid = (int)$pidStr;
            $project = Board::project($client, $pid);
            $names[$pid] = (string)($project['name'] ?? "#$pid");
            $rows = array_values(array_filter(Board::openTasks($client, $project), fn($t) =>
                ($t['agent_state'] ?? null) === null
                && (($t['assignee_id'] ?? null) === null || (int)$t['assignee_id'] === $botId)));
            if ($rows === []) { continue; }
            $critical = Board::criticalTaskIds($client, $pid, $cfg->approval['critical_tags']);
            foreach (Rank::sort($rows, $critical, $now) as $t) { $all[] = $t; }
        }
        usort($all, fn($a, $b) => [$b['rank']['score'], (int)$a['id']] <=> [$a['rank']['score'], (int)$b['id']]);
        return [$all, count($all), $names];
    }
}
