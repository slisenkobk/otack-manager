<?php
// See test_agent_config.php for why this guard exists: tests/run.php's bare
// top-level `require` turns a missing-file require_once into a fatal that
// aborts the whole suite. This keeps the red step inside a green run.
$__impl = dirname(__DIR__, 2) . '/agent/lib/Triage.php';
if (!is_file($__impl)) {
    it('agent/lib/Triage.php exists', fn() => assert_true(false, 'not implemented yet'));
    return;
}
require_once $__impl;

use App\Agent\Triage;

function _c(int $id, int $user): array { return ['id' => $id, 'user_id' => $user]; }
function _t(int $id, ?string $state, bool $fromForm = false): array {
    return ['id' => $id, 'agent_state' => $state, 'from_form' => $fromForm];
}

it('newComments returns everything after the last comment by self', function () {
    $out = Triage::newComments([_c(1, 5), _c(2, 9), _c(3, 5), _c(4, 9), _c(5, 5)], 9);
    assert_eq([5], array_column($out, 'id'));
});

it('newComments returns the whole thread when self has never commented', function () {
    $out = Triage::newComments([_c(1, 5), _c(2, 7)], 9);
    assert_eq([1, 2], array_column($out, 'id'));
});

it('newComments returns nothing when self commented last', function () {
    $out = Triage::newComments([_c(1, 5), _c(2, 9)], 9);
    assert_eq([], $out);
});

it('newComments is not confused by ids arriving out of order', function () {
    $out = Triage::newComments([_c(5, 9), _c(2, 5), _c(7, 5)], 9);
    assert_eq([7], array_column($out, 'id'));
});

it('pick resumes an in-flight task before starting anything new', function () {
    $r = Triage::pick([_t(1, null), _t(2, 'implementing')], []);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick treats researching as in-flight too', function () {
    $r = Triage::pick([_t(1, null), _t(2, 'researching')], []);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick takes review feedback before starting new work', function () {
    $r = Triage::pick([_t(1, null), _t(3, 'review')], [3 => 2]);
    assert_eq(3, $r['task']['id']);
    assert_eq('rework', $r['reason']);
});

it('pick ignores a review task nobody has commented on', function () {
    $r = Triage::pick([_t(1, null), _t(3, 'review')], [3 => 0]);
    assert_eq(1, $r['task']['id']);
    assert_eq('new', $r['reason']);
});

it('pick takes an answered approval question before starting new work', function () {
    $r = Triage::pick([_t(1, null), _t(4, 'awaiting_approval')], [4 => 1]);
    assert_eq(4, $r['task']['id']);
    assert_eq('approved', $r['reason']);
});

it('pick leaves an unanswered approval alone and moves on', function () {
    $r = Triage::pick([_t(1, null), _t(4, 'awaiting_approval')], [4 => 0]);
    assert_eq(1, $r['task']['id']);
});

it('pick never takes a task created from a public form', function () {
    $r = Triage::pick([_t(8, null, true)], []);
    assert_eq(null, $r);
});

it('pick still returns a task when from_form is absent entirely — this is why Task 4 must verify the winner against GET /tasks/{id}', function () {
    // GET /tasks returns list items, and TasksHandler::serializeListItem()
    // never emits `from_form` at all (only serializeOne() does, for
    // GET /tasks/{id}). Feed pick() a task shaped exactly like a list item —
    // no `from_form` key, not even false — and it has nothing to filter on.
    // pick() is not the safety net for form-created tasks by itself; the
    // caller's post-pick verification is.
    $r = Triage::pick([['id' => 11, 'agent_state' => null]], []);
    assert_eq(11, $r['task']['id']);
});

it('pick never takes a blocked task', function () {
    $r = Triage::pick([_t(9, 'blocked')], [9 => 3]);
    assert_eq(null, $r);
});

it('pick returns null when there is nothing to do', function () {
    assert_eq(null, Triage::pick([], []));
});

it('nextPosition starts an empty column at 1024.0', function () {
    assert_eq(1024.0, Triage::nextPosition([]));
});

it('nextPosition appends past the current maximum in the column', function () {
    $pos = Triage::nextPosition([['position' => 1024.0], ['position' => 3200.5], ['position' => 2048.0]]);
    assert_eq(4224.5, $pos);
});
