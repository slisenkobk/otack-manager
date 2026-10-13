<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Owner's task selection through a Telegram digest (owner decision
 * 2026-10-13). State lives in ~/.claude/otack-agent/selection.json:
 *   offset   — next getUpdates offset (each update is applied once)
 *   digest   — the one live digest; taps on any other message are stale
 *   approved — tasks the owner tapped that the bot has not started yet
 */
final class Selection
{
    private const MAX_TEXT = 4096;
    private const PRIORITY_RU = ['urgent' => 'срочный', 'high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'];

    public static function empty(): array
    {
        return ['offset' => 0, 'digest' => null, 'approved' => []];
    }

    public static function load(string $path): array
    {
        if (!is_file($path)) { return self::empty(); }
        $s = json_decode((string)file_get_contents($path), true);
        if (!is_array($s)) {
            throw new \RuntimeException("$path is not valid JSON — delete it to reset the Telegram selection");
        }
        $s = array_replace(self::empty(), array_intersect_key($s, self::empty()));
        $s['offset'] = (int)$s['offset'];
        $d = $s['digest'];
        $s['digest'] = is_array($d) && isset($d['message_id'], $d['sent_at']) && is_array($d['offered'] ?? null)
            ? ['message_id' => (int)$d['message_id'], 'sent_at' => (int)$d['sent_at'],
               'offered' => self::ids($d['offered']), 'answered' => (bool)($d['answered'] ?? false)]
            : null;
        $s['approved'] = self::ids($s['approved']);
        return $s;
    }

    public static function save(string $path, array $s): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $old = umask(077);
        try {
            if (file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
                throw new \RuntimeException("cannot write $tmp");
            }
            chmod($tmp, 0600);
            if (!rename($tmp, $path)) { throw new \RuntimeException("cannot replace $path"); }
        } finally {
            umask($old);
            if (is_file($tmp)) { @unlink($tmp); }
        }
    }

    public static function applyUpdates(array $state, array $updates, string $chatId): array
    {
        $acks = [];
        $newly = [];
        foreach ($updates as $u) {
            if (!is_array($u)) { continue; }
            $state['offset'] = max((int)$state['offset'], (int)($u['update_id'] ?? 0) + 1);
            $cb = $u['callback_query'] ?? null;
            if (!is_array($cb)) { continue; }
            $ack = function (string $text) use (&$acks, $cb): void {
                $acks[] = ['callback_id' => (string)($cb['id'] ?? ''), 'text' => $text];
            };
            $from = (string)($cb['from']['id'] ?? '');
            $chat = (string)($cb['message']['chat']['id'] ?? '');
            if ($chatId === '' || $from !== $chatId || $chat !== $chatId) { $ack('Нет доступа'); continue; }
            $digest = $state['digest'];
            if (!is_array($digest) || (int)($cb['message']['message_id'] ?? 0) !== (int)$digest['message_id']) {
                $ack('Этот список устарел');
                continue;
            }
            $data = (string)($cb['data'] ?? '');
            if ($data === 'skip') {
                $state['digest']['answered'] = true;
                $ack('Ок, ничего не берём');
                continue;
            }
            if (preg_match('/^take:(\d{1,10})\z/', $data, $m) && in_array((int)$m[1], $digest['offered'], true)) {
                $id = (int)$m[1];
                if (!in_array($id, $state['approved'], true)) {
                    $state['approved'][] = $id;
                    $newly[] = $id;
                }
                $state['digest']['answered'] = true;
                $ack("Взято: #$id");
                continue;
            }
            $ack('Неизвестная кнопка');
        }
        return [$state, $acks, $newly];
    }

    public static function needsDigest(array $state, \DateTimeImmutable $now, int $everyHours): bool
    {
        if ($state['approved'] !== []) { return false; }
        if (!is_array($state['digest'])) { return true; }
        return $now->getTimestamp() - (int)$state['digest']['sent_at'] >= $everyHours * 3600;
    }

    public static function digest(array $ranked, array $projectNames, int $total): array
    {
        $items = [];
        foreach (array_values($ranked) as $i => $t) {
            $n = $i + 1;
            $id = (int)$t['id'];
            $why = implode(', ', array_map([self::class, 'reasonLabel'], $t['rank']['reasons'] ?? []));
            $project = $projectNames[(int)($t['project_id'] ?? 0)] ?? ('проект ' . (int)($t['project_id'] ?? 0));
            $items[] = [
                'id'     => $id,
                'line'   => sprintf('%d. #%d %s — %s%s',
                    $n, $id,
                    self::esc(mb_substr(self::oneLine((string)($t['title'] ?? '')), 0, 90)),
                    self::esc(mb_substr(self::oneLine((string)$project), 0, 30)),
                    $why !== '' ? ' <i>(' . self::esc($why) . ')</i>' : ''),
                'button' => ['text' => "✅ $n · #$id", 'callback_data' => "take:$id"],
            ];
        }
        // Telegram rejects text over 4096 UTF-16 units (an emoji is two) and
        // escaping can inflate a title several times, so measure in UTF-16
        // (markup included, i.e. conservatively) and drop tasks from the
        // bottom until the message fits.
        // Only what is shown is offered — a dropped task gets no button.
        do {
            $lines = ['<b>Otack: что берём в работу?</b>', 'Показаны ' . count($items) . " из $total, самые важные сверху.", ''];
            foreach ($items as $it) { $lines[] = $it['line']; }
            $text = implode("\n", $lines);
        } while (self::utf16Len($text) > self::MAX_TEXT && array_pop($items) !== null);
        $rows = array_chunk(array_column($items, 'button'), 2);
        $rows[] = [['text' => 'Ничего не брать', 'callback_data' => 'skip']];
        return [
            'text'         => $text,
            'reply_markup' => ['inline_keyboard' => $rows],
            'offered'      => array_column($items, 'id'),
        ];
    }

    public static function reasonLabel(string $r): string
    {
        if (str_starts_with($r, 'priority:')) {
            $p = substr($r, 9);
            return 'приоритет ' . (self::PRIORITY_RU[$p] ?? $p);
        }
        if (str_starts_with($r, 'age:')) {
            return 'ждёт ' . rtrim(substr($r, 4), 'w') . ' нед.';
        }
        return match ($r) {
            'critical_tag' => 'критичный тег',
            'overdue'      => 'просрочена',
            'due_soon'     => 'срок ≤ 3 дней',
            'due_week'     => 'срок ≤ 7 дней',
            default        => $r,
        };
    }

    /** Positive integer ids only, each once — anything else in the file is dropped. */
    private static function ids(mixed $list): array
    {
        if (!is_array($list)) { return []; }
        return array_values(array_unique(array_filter($list, fn($x) => is_int($x) && $x > 0)));
    }

    /** A title must not break the numbered list with its own newlines. */
    private static function oneLine(string $s): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    private static function utf16Len(string $s): int
    {
        return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
