<?php
use App\Service\TelegramNotifier;
use App\Service\NotificationLogger;
use App\Repository\NotificationLogRepository;

// --- escape() ---------------------------------------------------------------

it('TelegramNotifier::escape HTML-escapes <, >, &', function () {
    assert_eq('&lt;b&gt;hi&lt;/b&gt;', TelegramNotifier::escape('<b>hi</b>'));
    assert_eq('a &amp; b', TelegramNotifier::escape('a & b'));
});

it('TelegramNotifier::escape preserves Unicode (Cyrillic, CJK)', function () {
    assert_eq('Привіт', TelegramNotifier::escape('Привіт'));
    assert_eq('こんにちは', TelegramNotifier::escape('こんにちは'));
    assert_eq('中文 &amp; テスト', TelegramNotifier::escape('中文 & テスト'));
});

it('TelegramNotifier::escape handles empty string', function () {
    assert_eq('', TelegramNotifier::escape(''));
});

it('TelegramNotifier::escape forAttr mode also escapes quotes', function () {
    assert_eq('a &quot;b&quot; c', TelegramNotifier::escape('a "b" c', true));
    // default mode leaves quotes alone (HTML body context)
    assert_eq('a "b" c', TelegramNotifier::escape('a "b" c', false));
});

// --- send() URL handling (build path) ---------------------------------------

it('TelegramNotifier::send skips when token/chatId empty', function () {
    $n = new TelegramNotifier('', '');
    $r = $n->send('[TASK] Hello', 'https://example.com');
    assert_true($r['ok'] === true);
    assert_eq('skipped', $r['error']);
});

it('TelegramNotifier::send skips when only token empty', function () {
    $n = new TelegramNotifier('', 'chat-1');
    $r = $n->send('hi');
    assert_eq('skipped', $r['error']);
});

// --- NotificationLogger wrapper ---------------------------------------------

it('NotificationLogger::flush logs skipped notification to repository', function () {
    // In-memory PDO + migration → real repo, exercise the skipped path.
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration(
        $pdo,
        dirname(__DIR__, 2) . '/system/Database/migrations/20260522_100_notifications_log.php'
    );
    $repo = new NotificationLogRepository($pdo);
    $notifier = new TelegramNotifier('', ''); // forces skipped
    $logger = new NotificationLogger($notifier, $repo);

    $logger->notify('task.created', '[TASK] Hi', null, ['task_id' => 42]);
    $logger->flush();

    $rows = $pdo->query('SELECT event, ok, error FROM notifications_log')
        ->fetchAll(PDO::FETCH_ASSOC);
    assert_eq(1, count($rows));
    assert_eq('task.created', $rows[0]['event']);
    // ok = true (skip is considered an "ok" non-failure), error = 'skipped'
    assert_eq('skipped', $rows[0]['error']);
});
