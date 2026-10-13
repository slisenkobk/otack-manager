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

// Prefixed _triage_ (not the generic _c/_t) because tests/run.php requires
// every tests/unit/test_*.php into one shared global scope — a second file
// declaring a same-named helper is a fatal redeclare that aborts the whole
// suite, not a single failing test.
function _triage_c(int $id, int $user): array { return ['id' => $id, 'user_id' => $user]; }
function _triage_t(int $id, ?string $state, bool $fromForm = false): array {
    return ['id' => $id, 'agent_state' => $state, 'from_form' => $fromForm];
}

it('newComments returns everything after the last comment by self', function () {
    $out = Triage::newComments([_triage_c(1, 5), _triage_c(2, 9), _triage_c(3, 5), _triage_c(4, 9), _triage_c(5, 5)], 9);
    assert_eq([5], array_column($out, 'id'));
});

it('newComments returns the whole thread when self has never commented', function () {
    $out = Triage::newComments([_triage_c(1, 5), _triage_c(2, 7)], 9);
    assert_eq([1, 2], array_column($out, 'id'));
});

it('newComments returns nothing when self commented last', function () {
    $out = Triage::newComments([_triage_c(1, 5), _triage_c(2, 9)], 9);
    assert_eq([], $out);
});

it('newComments is not confused by ids arriving out of order', function () {
    $out = Triage::newComments([_triage_c(5, 9), _triage_c(2, 5), _triage_c(7, 5)], 9);
    assert_eq([7], array_column($out, 'id'));
});

// MINOR 4: the earlier out-of-order test above yields a single surviving
// element, so it can't tell an `usort` apart from no sort at all. This one
// has two survivors whose input order is the reverse of id order — deleting
// the `usort` in newComments() makes this fail because the agent feeds this
// thread to a model as chronological context and [7, 3] is not chronological.
it('newComments returns survivors in ascending id order regardless of input order', function () {
    $out = Triage::newComments([_triage_c(7, 5), _triage_c(3, 5)], 9);
    assert_eq([3, 7], array_column($out, 'id'));
});

// MINOR 6: a non-positive selfUserId must fail loud rather than silently
// produce a watermark of 0 that never matches — which would replay the
// entire thread as "new" on every tick, indistinguishable from the
// legitimate "never commented" case.
it('newComments throws on a non-positive selfUserId', function () {
    $threw = false;
    try {
        Triage::newComments([_triage_c(1, 5)], 0);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected InvalidArgumentException for selfUserId=0');
});

it('newComments throws on a negative selfUserId', function () {
    $threw = false;
    try {
        Triage::newComments([_triage_c(1, 5)], -3);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected InvalidArgumentException for selfUserId=-3');
});

it('pick resumes an in-flight task before starting anything new', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(2, 'implementing')], []);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick treats researching as in-flight too', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(2, 'researching')], []);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick takes review feedback before starting new work', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(3, 'review')], [3 => 2]);
    assert_eq(3, $r['task']['id']);
    assert_eq('rework', $r['reason']);
});

it('pick ignores a review task nobody has commented on', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(3, 'review')], [3 => 0]);
    assert_eq(1, $r['task']['id']);
    assert_eq('new', $r['reason']);
});

it('pick takes an answered approval question before starting new work', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(4, 'awaiting_approval')], [4 => 1]);
    assert_eq(4, $r['task']['id']);
    assert_eq('approved', $r['reason']);
});

it('pick leaves an unanswered approval alone and moves on', function () {
    $r = Triage::pick([_triage_t(1, null), _triage_t(4, 'awaiting_approval')], [4 => 0]);
    assert_eq(1, $r['task']['id']);
});

it('pick never takes a task created from a public form', function () {
    $r = Triage::pick([_triage_t(8, null, true)], []);
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
    $r = Triage::pick([_triage_t(9, 'blocked')], [9 => 3]);
    assert_eq(null, $r);
});

// MAJOR 2 (part 2 of 2): `blocked` matches no rung's state list, so the
// blocked exclusion above is vacuous by itself — deleting it changes
// nothing. The real guard against picking up a blocked (or otherwise
// unrecognized) task as fresh work is the strict `$byState([null])` match
// on the `new` rung. Pin that directly: a task whose agent_state is some
// unrecognized string (not one of the four known states, and not the
// literal null that means "never started") must never be picked.
it('pick never starts a task whose agent_state is neither null nor a known state', function () {
    $r = Triage::pick([_triage_t(5, 'some_unknown_state')], []);
    assert_eq(null, $r);
});

it('pick returns null when there is nothing to do', function () {
    assert_eq(null, Triage::pick([], []));
});

// MAJOR 1: every existing ladder test above pairs exactly one non-`new`
// candidate against a `new` candidate, so only the swap against `new` is
// caught. These three pin the three rungs against each other directly, with
// BOTH candidates present, per the review: resume > rework > approved.
it('pick ladder: resume beats rework when both are present', function () {
    $r = Triage::pick([_triage_t(2, 'implementing'), _triage_t(3, 'review')], [3 => 1]);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick ladder: resume beats approved when both are present', function () {
    $r = Triage::pick([_triage_t(2, 'implementing'), _triage_t(4, 'awaiting_approval')], [4 => 1]);
    assert_eq(2, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('pick ladder: rework beats approved when both are present', function () {
    $r = Triage::pick([_triage_t(3, 'review'), _triage_t(4, 'awaiting_approval')], [3 => 1, 4 => 1]);
    assert_eq(3, $r['task']['id']);
    assert_eq('rework', $r['reason']);
});

// MINOR 3: within a rung, the lowest id wins — pinned with two same-rung
// tasks fed in reversed (descending) order, so array order alone cannot
// explain the winner.
it('pick breaks a same-rung tie by lowest id, independent of input order', function () {
    $r = Triage::pick([_triage_t(9, 'implementing'), _triage_t(4, 'implementing')], []);
    assert_eq(4, $r['task']['id']);
    assert_eq('resume', $r['reason']);
});

it('nextPosition starts an empty column at 1024.0', function () {
    assert_eq(1024.0, Triage::nextPosition([]));
});

it('nextPosition appends past the current maximum in the column', function () {
    $pos = Triage::nextPosition([['position' => 1024.0], ['position' => 3200.5], ['position' => 2048.0]]);
    assert_eq(4224.5, $pos);
});

// MINOR 7: a position already near PHP_FLOAT_MAX makes `$max + 1024.0` tie
// with $max instead of exceeding it — the moved card would collide with the
// card it was supposed to follow.
it('nextPosition throws instead of silently colliding on a huge existing position', function () {
    $threw = false;
    try {
        Triage::nextPosition([['position' => 1e308]]);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected RuntimeException when max + 1024.0 does not exceed max');
});

// MINOR 7: an already-infinite position (e.g. (float)'1e400' == INF) makes
// the sum INF too. json_encode(['position' => INF]) returns false, so the
// move request body would silently serialise to an empty string with no
// downstream bounds check to catch it (TasksHandler.php casts with (float)).
it('nextPosition throws instead of producing a non-finite position', function () {
    $threw = false;
    try {
        Triage::nextPosition([['position' => INF]]);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected RuntimeException when the computed position is not finite');
});

it('pick with an approved list starts only approved new tasks; other rungs unaffected', function () {
    $tasks = [['id' => 5, 'agent_state' => null], ['id' => 6, 'agent_state' => null], ['id' => 9, 'agent_state' => 'implementing']];
    assert_eq(['task' => $tasks[2], 'reason' => 'resume'], App\Agent\Triage::pick($tasks, [], []));
    $new = [['id' => 5, 'agent_state' => null], ['id' => 6, 'agent_state' => null]];
    assert_eq(null, App\Agent\Triage::pick($new, [], []));
    assert_eq(6, App\Agent\Triage::pick($new, [], [6])['task']['id']);
    assert_eq(5, App\Agent\Triage::pick($new, [], null)['task']['id'], 'null = no approval gate');
});
