<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/Selection.php';
if (!is_file($__lib)) { it('agent/lib/Selection.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
require_once $__lib;
use App\Agent\Selection;

function _cb(int $updateId, string $data, int $msgId = 500, string $from = '42', string $chat = '42'): array {
    return ['update_id' => $updateId, 'callback_query' => [
        'id' => "cb$updateId", 'from' => ['id' => (int)$from], 'data' => $data,
        'message' => ['message_id' => $msgId, 'chat' => ['id' => (int)$chat]],
    ]];
}
function _stateWithDigest(): array {
    $s = Selection::empty();
    $s['digest'] = ['message_id' => 500, 'sent_at' => 1_000_000, 'offered' => [11, 12], 'answered' => false];
    return $s;
}

it('a tap on an offered task approves it once and advances the offset', function () {
    [$s, $acks, $new] = Selection::applyUpdates(_stateWithDigest(), [_cb(7, 'take:11'), _cb(8, 'take:11')], '42');
    assert_eq([11], $s['approved']);
    assert_eq([11], $new);
    assert_eq(9, $s['offset']);
    assert_eq(true, $s['digest']['answered']);
    assert_eq(['callback_id' => 'cb7', 'text' => 'Взято: #11'], $acks[0]);
});

it('taps from another user or chat, an old digest, an unoffered id or forged data are never approved', function () {
    [$s, $acks, $new] = Selection::applyUpdates(_stateWithDigest(), [
        _cb(1, 'take:11', 500, '99', '42'),
        _cb(2, 'take:11', 500, '42', '-100'),
        _cb(3, 'take:11', 499),
        _cb(4, 'take:13'),
        _cb(5, 'take:11;rm'),
        _cb(6, 'TAKE:11'),
    ], '42');
    assert_eq([], $s['approved']);
    assert_eq([], $new);
    assert_eq(7, $s['offset']);
    assert_eq(6, count($acks));
    assert_eq('Этот список устарел', $acks[2]['text']);
});

it('skip marks the digest answered without approving', function () {
    [$s, , $new] = Selection::applyUpdates(_stateWithDigest(), [_cb(1, 'skip')], '42');
    assert_eq([], $new);
    assert_eq(true, $s['digest']['answered']);
});

it('non-callback updates only move the offset', function () {
    [$s, $acks] = Selection::applyUpdates(_stateWithDigest(), [['update_id' => 3, 'message' => ['text' => 'take:11']]], '42');
    assert_eq(4, $s['offset']);
    assert_eq([], $acks);
    assert_eq([], $s['approved']);
});

it('needsDigest: first time yes; queued approvals no; then after the interval', function () {
    $now = new DateTimeImmutable('@' . (1_000_000 + 3600));
    assert_true(Selection::needsDigest(Selection::empty(), $now, 24));
    $s = _stateWithDigest();
    assert_true(!Selection::needsDigest($s, $now, 24));
    assert_true(Selection::needsDigest($s, new DateTimeImmutable('@' . (1_000_000 + 24 * 3600)), 24));
    $s['approved'] = [11];
    assert_true(!Selection::needsDigest($s, new DateTimeImmutable('@' . (1_000_000 + 48 * 3600)), 24));
});

it('digest numbers tasks, escapes HTML, explains the rank, and offers buttons', function () {
    $d = Selection::digest([
        ['id' => 11, 'project_id' => 3, 'title' => 'Fix <script> in forms', 'rank' => ['score' => 550, 'reasons' => ['priority:urgent', 'overdue']]],
        ['id' => 12, 'project_id' => 3, 'title' => 'Docs', 'rank' => ['score' => 0, 'reasons' => []]],
    ], [3 => 'Otack CMS'], 30);
    assert_true(str_contains($d['text'], '1. #11 Fix &lt;script&gt; in forms — Otack CMS <i>(приоритет срочный, просрочена)</i>'), $d['text']);
    assert_true(str_contains($d['text'], '2 из 30'), $d['text']);
    assert_eq([11, 12], $d['offered']);
    assert_eq('take:11', $d['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    assert_eq('skip', end($d['reply_markup']['inline_keyboard'])[0]['callback_data']);
});

it('digest stays under Telegram\'s 4096-char limit with 20 long titles', function () {
    $rows = [];
    for ($i = 1; $i <= 20; $i++) { $rows[] = ['id' => $i, 'project_id' => 1, 'title' => str_repeat('Ж', 400), 'rank' => ['score' => 1, 'reasons' => ['priority:high', 'critical_tag', 'overdue', 'age:30w']]]; }
    assert_true(mb_strlen(Selection::digest($rows, [1 => str_repeat('П', 200)], 20)['text']) <= 4096);
});

it('save/load round-trips with 0600 and load of a missing file is empty', function () {
    $p = sys_get_temp_dir() . '/otack-sel-' . bin2hex(random_bytes(4)) . '.json';
    assert_eq(Selection::empty(), Selection::load($p));
    Selection::save($p, _stateWithDigest());
    assert_eq(_stateWithDigest(), Selection::load($p));
    $perms = fileperms($p) & 0777;
    @unlink($p);
    assert_eq(0600, $perms);
});

it('digest guards Telegram limits: callback_data <= 64 bytes and text <= 4096 even when escaping inflates titles', function () {
    $rows = [];
    for ($i = 1; $i <= 20; $i++) { $rows[] = ['id' => 9_999_999_000 + $i, 'project_id' => 1, 'title' => str_repeat('<&>', 200), 'rank' => ['score' => 1, 'reasons' => ['priority:urgent', 'critical_tag', 'overdue', 'due_soon', 'age:300w']]]; }
    $d = Selection::digest($rows, [1 => str_repeat('&', 200)], 500);
    assert_true(mb_strlen($d['text']) <= 4096, (string)mb_strlen($d['text']));
    assert_true(count($d['offered']) >= 1);
    $taps = 0;
    foreach ($d['reply_markup']['inline_keyboard'] as $row) {
        foreach ($row as $btn) {
            assert_true(strlen($btn['callback_data']) <= 64, $btn['callback_data']);
            if ($btn['callback_data'] !== 'skip') { $taps++; }
        }
    }
    assert_eq(count($d['offered']), $taps);
});

it('load treats a malformed digest as no digest', function () {
    $p = sys_get_temp_dir() . '/otack-sel-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($p, json_encode(['offset' => 5, 'digest' => ['sent_at' => 1], 'approved' => [3]]));
    $s = Selection::load($p);
    @unlink($p);
    assert_eq(null, $s['digest']);
    assert_eq([3], $s['approved']);
    assert_eq(5, $s['offset']);
});

it('digest counts emoji as two UTF-16 units the way Telegram does', function () {
    $rows = [];
    for ($i = 1; $i <= 20; $i++) { $rows[] = ['id' => $i, 'project_id' => 1, 'title' => str_repeat('😀', 200), 'rank' => ['score' => 1, 'reasons' => ['priority:urgent', 'critical_tag', 'overdue', 'age:300w']]]; }
    $text = Selection::digest($rows, [1 => 'P'], 20)['text'];
    assert_true(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 <= 4096);
});

it('an empty configured chat id approves nothing, even a tap with no from/chat', function () {
    $u = ['update_id' => 1, 'callback_query' => ['id' => 'cb1', 'data' => 'take:11', 'message' => ['message_id' => 500]]];
    [$s, $acks, $new] = Selection::applyUpdates(_stateWithDigest(), [$u, _cb(2, 'take:11')], '');
    assert_eq([], $new);
    assert_eq([], $s['approved']);
    assert_eq('Нет доступа', $acks[0]['text']);
});

it('take with a trailing newline is not approved', function () {
    [$s, $acks, $new] = Selection::applyUpdates(_stateWithDigest(), [_cb(1, "take:11\n")], '42');
    assert_eq([], $new);
    assert_eq([], $s['approved']);
    assert_eq('Неизвестная кнопка', $acks[0]['text']);
});

it('load keeps only positive integer ids, deduplicated, in approved and offered', function () {
    $p = sys_get_temp_dir() . '/otack-sel-' . bin2hex(random_bytes(4)) . '.json';
    $junk = ['x', [5], 0, 7, 7];
    file_put_contents($p, json_encode(['offset' => 1, 'approved' => $junk,
        'digest' => ['message_id' => 500, 'sent_at' => 1, 'offered' => $junk, 'answered' => false]]));
    $s = Selection::load($p);
    @unlink($p);
    assert_eq([7], $s['approved']);
    assert_eq([7], $s['digest']['offered']);
});

it('digest collapses whitespace so a title cannot fake another list line', function () {
    $d = Selection::digest([
        ['id' => 11, 'project_id' => 3, 'title' => "Real\n3. #99  fake\ttask", 'rank' => ['score' => 0, 'reasons' => []]],
    ], [3 => "Otack\nCMS"], 1);
    assert_true(str_contains($d['text'], '1. #11 Real 3. #99 fake task — Otack CMS'), $d['text']);
    assert_true(!str_contains($d['text'], "\n3. #99"), $d['text']);
});
