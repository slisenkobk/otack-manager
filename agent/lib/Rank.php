<?php
declare(strict_types=1);

namespace App\Agent;

/**
 * Orders open tasks by "what should be taken next". The API has priority,
 * due_date, tags and created_at — no separate importance/criticality field —
 * so criticality is expressed as priority plus configured critical tags
 * (approval.critical_tags). Pure: every input is passed in, including "now".
 */
final class Rank
{
    public const PRIORITY_WEIGHT = ['urgent' => 400, 'high' => 300, 'medium' => 200, 'low' => 100, 'none' => 0];

    /** @return array{score:int, reasons:list<string>} */
    public static function score(array $task, bool $critical, \DateTimeImmutable $now): array
    {
        $score = 0;
        $reasons = [];

        $priority = is_string($task['priority'] ?? null) ? $task['priority'] : 'none';
        $weight = self::PRIORITY_WEIGHT[$priority] ?? 0;
        if ($weight > 0) {
            $score += $weight;
            $reasons[] = "priority:$priority";
        }

        if ($critical) {
            $score += 120;
            $reasons[] = 'critical_tag';
        }

        $due = $task['due_date'] ?? null;
        if (is_string($due) && preg_match('/^\d{4}-\d{2}-\d{2}/', $due)) {
            $dueDay = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($due, 0, 10), $now->getTimezone());
            // createFromFormat() rolls '2026-02-31' over to March 3rd and
            // '0000-00-00' to year -1: an impossible date is ignored, never
            // read as "overdue".
            if ($dueDay !== false && $dueDay->format('Y-m-d') === substr($due, 0, 10)) {
                $days = (int)$now->setTime(0, 0)->diff($dueDay)->format('%r%a');
                if ($days < 0)      { $score += 150; $reasons[] = 'overdue'; }
                elseif ($days <= 3) { $score += 100; $reasons[] = 'due_soon'; }
                elseif ($days <= 7) { $score += 50;  $reasons[] = 'due_week'; }
            }
        }

        $created = $task['created_at'] ?? null;
        if (is_string($created) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]/', $created)) {
            try {
                $weeks = intdiv(max(0, $now->getTimestamp() - (new \DateTimeImmutable($created))->getTimestamp()), 7 * 86400);
                if ($weeks > 0) {
                    $score += min(30, $weeks);
                    $reasons[] = 'age:' . $weeks . 'w';
                }
            } catch (\Exception) {
                // malformed timestamp: no age bonus
            }
        }

        return ['score' => $score, 'reasons' => $reasons];
    }

    /** @param array<int,true> $criticalIds */
    public static function sort(array $tasks, array $criticalIds, \DateTimeImmutable $now): array
    {
        foreach ($tasks as &$t) {
            $t['rank'] = self::score($t, isset($criticalIds[(int)($t['id'] ?? 0)]), $now);
        }
        unset($t);
        usort($tasks, fn($a, $b) => [$b['rank']['score'], (int)($a['id'] ?? 0)] <=> [$a['rank']['score'], (int)($b['id'] ?? 0)]);
        return $tasks;
    }
}
