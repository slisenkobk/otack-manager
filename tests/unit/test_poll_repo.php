<?php
use App\Repository\PollRepository;
use App\Repository\PollVoteRepository;

function _pollPdo(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    (require dirname(__DIR__, 2) . '/system/Database/migrations/20260603_010_polls.php')($pdo);
    (require dirname(__DIR__, 2) . '/system/Database/migrations/20260603_020_poll_votes.php')($pdo);
    return $pdo;
}

it('PollRepository::normalizeContact lowercases email and digits-only phone', function () {
    assert_eq('alice@example.com', PollRepository::normalizeContact('  Alice@Example.COM ', 'email'));
    assert_eq('380501234567',      PollRepository::normalizeContact('+38 (050) 123-45-67', 'phone'));
    assert_eq('',                  PollRepository::normalizeContact('   ', 'email'));
});

it('PollRepository::defaultFields seeds locked contact + radio with options', function () {
    $fields = PollRepository::defaultFields('email');
    assert_eq(2, count($fields));
    assert_eq('contact', $fields[0]['key']);
    assert_eq('email',   $fields[0]['type']);
    assert_true((bool)$fields[0]['locked']);
    assert_eq('choice',  $fields[1]['key']);
    assert_eq('radio',   $fields[1]['type']);
    assert_eq(2,         count($fields[1]['options']));

    $phoneFields = PollRepository::defaultFields('phone');
    assert_eq('tel', $phoneFields[0]['type']);
});

it('PollRepository create + findById + findByHash', function () {
    $repo = new PollRepository(_pollPdo());
    $id = $repo->create('Q1', 'desc', PollRepository::defaultFields(), [], 7);
    assert_true($id > 0);
    $row = $repo->findById($id);
    assert_eq('Q1', $row['title']);
    assert_eq('draft', $row['status']);
    assert_eq('email', $row['contact_field']);
    assert_true($row['hash'] !== '' && strlen($row['hash']) === 12);
    $byHash = $repo->findByHash($row['hash']);
    assert_eq($id, (int)$byHash['id']);
});

it('PollRepository status transitions draft → active → closed are one-way', function () {
    $repo = new PollRepository(_pollPdo());
    $id = $repo->create('Q', null, [], [], 1);
    // can activate from draft
    assert_true($repo->activate($id));
    assert_eq('active', $repo->findById($id)['status']);
    assert_true($repo->findById($id)['activated_at'] !== null);
    // can NOT re-activate
    assert_true(!$repo->activate($id));
    // can close from active
    assert_true($repo->close($id));
    assert_eq('closed', $repo->findById($id)['status']);
    assert_true($repo->findById($id)['closed_at'] !== null);
    // can NOT re-close
    assert_true(!$repo->close($id));
});

it('PollRepository cannot close directly from draft (must go through active)', function () {
    $repo = new PollRepository(_pollPdo());
    $id = $repo->create('Q', null, [], [], 1);
    assert_true(!$repo->close($id));
    assert_eq('draft', $repo->findById($id)['status']);
});

it('PollRepository::listForUser orders by status (active→draft→closed) then date', function () {
    $repo = new PollRepository(_pollPdo());
    // Insert with a deliberate creation order; sleep a tick between each so
    // created_at is strictly increasing in the file system clock.
    $draftOld = $repo->create('draft-old', null, [], [], 1);
    usleep(2000);
    $closed   = $repo->create('closed',    null, [], [], 1);
    usleep(2000);
    $active   = $repo->create('active',    null, [], [], 1);
    usleep(2000);
    $draftNew = $repo->create('draft-new', null, [], [], 1);

    $repo->activate($active);
    $repo->activate($closed); $repo->close($closed);

    $rows = $repo->listForUser(1, true);
    $order = array_map(fn($r) => $r['title'], $rows);
    // Expected: the single active first, both drafts (newest first), then closed.
    assert_eq(['active', 'draft-new', 'draft-old', 'closed'], $order);
});

it('PollRepository::listForUser scopes by ownership + status filter', function () {
    $repo = new PollRepository(_pollPdo());
    $a1 = $repo->create('Q1', null, [], [], 1);
    $a2 = $repo->create('Q2', null, [], [], 1);
    $b1 = $repo->create('Q3', null, [], [], 2);
    $repo->activate($a2);

    assert_eq(3, count($repo->listForUser(1, true)));               // admin sees all
    assert_eq(2, count($repo->listForUser(1, false)));              // owner sees own
    assert_eq(1, count($repo->listForUser(2, false)));
    assert_eq(1, count($repo->listForUser(1, true, 'active')));     // status filter
    assert_eq(2, count($repo->listForUser(1, true, 'draft')));
});

it('PollRepository::update merges allowed fields only and refuses status', function () {
    $repo = new PollRepository(_pollPdo());
    $id   = $repo->create('orig', null, [], [], 1);
    $repo->update($id, ['title' => 'renamed', 'status' => 'active', 'evil' => 'x']);
    $row = $repo->findById($id);
    assert_eq('renamed', $row['title']);
    assert_eq('draft',   $row['status']);   // status not in allowed list
});

it('PollVoteRepository::record enforces one-vote-per-contact', function () {
    $pdo = _pollPdo();
    $polls = new PollRepository($pdo);
    $votes = new PollVoteRepository($pdo);
    $id = $polls->create('Q', null, [], [], 1);

    $r1 = $votes->record($id, 'Alice@x.com', 'alice@x.com', 'opt_1', '1.2.3.4');
    assert_true($r1['ok']);

    $r2 = $votes->record($id, 'alice@x.com', 'alice@x.com', 'opt_2', '5.6.7.8');
    assert_true(!$r2['ok']);
    assert_eq('duplicate', $r2['reason']);

    // Different poll: same contact may vote.
    $other = $polls->create('Q2', null, [], [], 1);
    $r3 = $votes->record($other, 'alice@x.com', 'alice@x.com', 'opt_1');
    assert_true($r3['ok']);
});

it('PollVoteRepository::hasVoted', function () {
    $pdo = _pollPdo();
    $polls = new PollRepository($pdo);
    $votes = new PollVoteRepository($pdo);
    $id = $polls->create('Q', null, [], [], 1);

    assert_true(!$votes->hasVoted($id, 'a@b.c'));
    $votes->record($id, 'a@b.c', 'a@b.c', 'opt_1');
    assert_true($votes->hasVoted($id, 'a@b.c'));
});

it('PollVoteRepository::tallyByChoice groups + orders by count desc', function () {
    $pdo = _pollPdo();
    $polls = new PollRepository($pdo);
    $votes = new PollVoteRepository($pdo);
    $id = $polls->create('Q', null, [], [], 1);

    $votes->record($id, 'a@x', 'a@x', 'apple');
    $votes->record($id, 'b@x', 'b@x', 'apple');
    $votes->record($id, 'c@x', 'c@x', 'banana');
    $votes->record($id, 'd@x', 'd@x', 'apple');

    $tally = $votes->tallyByChoice($id);
    assert_eq(2, count($tally));
    assert_eq('apple', $tally[0]['choice_key']);
    assert_eq(3,       (int)$tally[0]['count']);
    assert_eq('banana', $tally[1]['choice_key']);
    assert_eq(1,        (int)$tally[1]['count']);
});

it('PollVoteRepository::listVoters returns newest first, offset-paginated', function () {
    $pdo = _pollPdo();
    $polls = new PollRepository($pdo);
    $votes = new PollVoteRepository($pdo);
    $id = $polls->create('Q', null, [], [], 1);

    for ($i = 1; $i <= 5; $i++) {
        $votes->record($id, "voter$i@x", "voter$i@x", 'opt_1');
    }
    $batch1 = $votes->listVoters($id, 0, 3);
    assert_eq(3, count($batch1));
    // newest at top
    assert_eq('voter5@x', $batch1[0]['contact']);

    $batch2 = $votes->listVoters($id, 3, 3);
    assert_eq(2, count($batch2));
    assert_eq('voter2@x', $batch2[0]['contact']);
});

it('PollRepository::detachSummaryTask clears the FK for matching polls', function () {
    $pdo = _pollPdo();
    $repo = new PollRepository($pdo);
    $a = $repo->create('A', null, [], [], 1);
    $b = $repo->create('B', null, [], [], 1);
    $c = $repo->create('C', null, [], [], 1);
    $repo->attachSummaryTask($a, 77);
    $repo->attachSummaryTask($b, 77);
    $repo->attachSummaryTask($c, 99);   // different task, untouched by the cleanup
    assert_eq(2, $repo->detachSummaryTask(77));
    assert_eq(null, $repo->findById($a)['summary_task_id']);
    assert_eq(null, $repo->findById($b)['summary_task_id']);
    assert_eq(99,   (int)$repo->findById($c)['summary_task_id']);
});

it('Deleting a poll cascades its votes', function () {
    $pdo = _pollPdo();
    $polls = new PollRepository($pdo);
    $votes = new PollVoteRepository($pdo);
    $id = $polls->create('Q', null, [], [], 1);
    $votes->record($id, 'a@x', 'a@x', 'opt_1');
    assert_eq(1, $votes->countTotal($id));
    $polls->delete($id);
    assert_eq(0, $votes->countTotal($id));
});
