<?php
use App\Service\NotificationLogger;
use App\Service\TelegramNotifier;
use App\Repository\NotificationLogRepository;

function _nl_pdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration(
        $pdo,
        dirname(__DIR__, 2) . '/system/Database/migrations/20260522_100_notifications_log.php'
    );
    return $pdo;
}

/** TelegramNotifier subclass that records send() calls without hitting the network. */
function _nl_fake_notifier(array &$calls, array $result, bool $shouldThrow = false): TelegramNotifier {
    return new class('tok', 'chat', $calls, $result, $shouldThrow) extends TelegramNotifier {
        public function __construct(
            string $t,
            string $c,
            private array &$calls,
            private array $result,
            private bool $shouldThrow,
        ) { parent::__construct($t, $c); }
        public function send(string $html, ?string $url = null): array {
            $this->calls[] = ['html' => $html, 'url' => $url];
            if ($this->shouldThrow) {
                throw new \RuntimeException('transport blew up');
            }
            return $this->result;
        }
    };
}

it('NotificationLogger::flush sends and logs ok=1 on success', function () {
    $pdo = _nl_pdo();
    $repo = new NotificationLogRepository($pdo);
    $calls = [];
    $notifier = _nl_fake_notifier($calls, ['ok' => true, 'error' => null]);
    $logger = new NotificationLogger($notifier, $repo);

    $logger->notify('task.created', '[TASK] Hello', 'https://ex.com/t/1', ['task_id' => 1]);
    $logger->flush();

    assert_eq(1, count($calls));
    assert_eq('[TASK] Hello', $calls[0]['html']);
    $rows = $pdo->query('SELECT event, ok, error FROM notifications_log')->fetchAll(PDO::FETCH_ASSOC);
    assert_eq(1, count($rows));
    assert_eq('task.created', $rows[0]['event']);
    assert_eq(1, (int)$rows[0]['ok']);
    assert_true($rows[0]['error'] === null || $rows[0]['error'] === '');
});

it('NotificationLogger::flush with skipped notifier still logs the row', function () {
    $pdo = _nl_pdo();
    $repo = new NotificationLogRepository($pdo);
    $calls = [];
    // skipped = ok=true, error='skipped' (the real TelegramNotifier shape).
    $notifier = _nl_fake_notifier($calls, ['ok' => true, 'error' => 'skipped']);
    $logger = new NotificationLogger($notifier, $repo);

    $logger->notify('task.moved', 'msg', null, []);
    $logger->flush();

    $rows = $pdo->query('SELECT error FROM notifications_log')->fetchAll(PDO::FETCH_ASSOC);
    assert_eq('skipped', $rows[0]['error']);
});

it('NotificationLogger::flush logs ok=0 with message when transport throws', function () {
    $pdo = _nl_pdo();
    $repo = new NotificationLogRepository($pdo);
    $calls = [];
    $notifier = _nl_fake_notifier($calls, [], true);
    $logger = new NotificationLogger($notifier, $repo);

    $logger->notify('task.created', 'msg', null, []);
    $logger->flush();

    $rows = $pdo->query('SELECT ok, error FROM notifications_log')->fetchAll(PDO::FETCH_ASSOC);
    assert_eq(1, count($rows));
    assert_eq(0, (int)$rows[0]['ok']);
    assert_true(str_contains((string)$rows[0]['error'], 'transport blew up'));
});

it('NotificationLogger::notify truncates body_text payload before logging', function () {
    $pdo = _nl_pdo();
    $repo = new NotificationLogRepository($pdo);
    $calls = [];
    $notifier = _nl_fake_notifier($calls, ['ok' => true, 'error' => null]);
    $logger = new NotificationLogger($notifier, $repo);

    $logger->notify('comment.created', 'msg', null, ['body_text' => str_repeat('x', 500)]);
    $logger->flush();

    $row = $pdo->query('SELECT payload FROM notifications_log')->fetch(PDO::FETCH_ASSOC);
    $payload = json_decode((string)$row['payload'], true);
    assert_eq(200, mb_strlen($payload['body_text']));
});
