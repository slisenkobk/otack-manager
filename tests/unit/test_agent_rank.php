<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/Rank.php';
if (!is_file($__lib)) { it('agent/lib/Rank.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
require_once $__lib;
use App\Agent\Rank;

$__now = new DateTimeImmutable('2026-10-13T12:00:00Z');

it('Rank orders by priority weight', function () use ($__now) {
    $rows = Rank::sort([
        ['id' => 1, 'priority' => 'low'], ['id' => 2, 'priority' => 'urgent'],
        ['id' => 3, 'priority' => 'none'], ['id' => 4, 'priority' => 'high'], ['id' => 5, 'priority' => 'medium'],
    ], [], $__now);
    assert_eq([2, 4, 5, 1, 3], array_map(fn($r) => $r['id'], $rows));
    assert_eq(['priority:urgent'], $rows[0]['rank']['reasons']);
});

it('Rank: overdue medium beats plain high; due soon and due this week add less', function () use ($__now) {
    assert_eq(['score' => 350, 'reasons' => ['priority:medium', 'overdue']], Rank::score(['id' => 1, 'priority' => 'medium', 'due_date' => '2026-10-12'], false, $__now));
    assert_eq(300, Rank::score(['id' => 2, 'priority' => 'high'], false, $__now)['score']);
    assert_eq(['due_soon'], Rank::score(['id' => 3, 'due_date' => '2026-10-16'], false, $__now)['reasons']);
    assert_eq(['due_week'], Rank::score(['id' => 4, 'due_date' => '2026-10-20'], false, $__now)['reasons']);
    assert_eq([], Rank::score(['id' => 5, 'due_date' => '2026-11-30'], false, $__now)['reasons']);
    assert_eq(['due_soon'], Rank::score(['id' => 6, 'due_date' => '2026-10-13'], false, $__now)['reasons'], 'due today is not overdue');
});

it('Rank: critical tag adds 120, age adds a week-capped bonus', function () use ($__now) {
    assert_eq(['score' => 120, 'reasons' => ['critical_tag']], Rank::score(['id' => 1], true, $__now));
    assert_eq(['score' => 3, 'reasons' => ['age:3w']], Rank::score(['id' => 1, 'created_at' => '2026-09-20T12:00:00Z'], false, $__now));
    assert_eq(30, Rank::score(['id' => 1, 'created_at' => '2020-01-01T00:00:00Z'], false, $__now)['score']);
});

it('Rank tolerates unknown priority and malformed dates', function () use ($__now) {
    assert_eq(['score' => 0, 'reasons' => []], Rank::score(['id' => 1, 'priority' => 'whatever', 'due_date' => 'soon', 'created_at' => 'yesterday-ish'], false, $__now));
    assert_eq(['score' => 0, 'reasons' => []], Rank::score(['id' => 1, 'priority' => null, 'due_date' => null], false, $__now));
});

it('Rank breaks ties by lowest id and uses the critical id map', function () use ($__now) {
    $rows = Rank::sort([['id' => 9], ['id' => 3], ['id' => 7]], [7 => true], $__now);
    assert_eq([7, 3, 9], array_map(fn($r) => $r['id'], $rows));
});
