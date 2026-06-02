<?php
use App\Repository\ShortLinkRepository;
use App\Repository\ShortLinkVisitRepository;

function _slPdo(): PDO {
    $pdo = \App\Database\Connection::open('sqlite::memory:');
    apply_migration($pdo, dirname(__DIR__, 2) . '/system/Database/migrations/20260602_020_short_links.php');
    return $pdo;
}

it('ShortLinkRepository::isAllowedUrl accepts http/https only', function () {
    assert_true(ShortLinkRepository::isAllowedUrl('https://example.com'));
    assert_true(ShortLinkRepository::isAllowedUrl('http://example.com/path?x=1'));
    assert_true(!ShortLinkRepository::isAllowedUrl('javascript:alert(1)'));
    assert_true(!ShortLinkRepository::isAllowedUrl('data:text/html,<script>'));
    assert_true(!ShortLinkRepository::isAllowedUrl('ftp://example.com'));
    assert_true(!ShortLinkRepository::isAllowedUrl(''));
    assert_true(!ShortLinkRepository::isAllowedUrl('not a url'));
    assert_true(!ShortLinkRepository::isAllowedUrl('https://'));
});

it('ShortLinkRepository::isAllowedUrl rejects user-info open-redirect bait', function () {
    // parse_url returns host=trusted.com for these — the validator must
    // catch the user-info component or browsers will navigate to evil.com.
    assert_true(!ShortLinkRepository::isAllowedUrl('https://evil.com@trusted.com'));
    assert_true(!ShortLinkRepository::isAllowedUrl('https://user:pass@example.com'));
});

it('ShortLinkRepository::isAllowedUrl rejects CR/LF/NUL', function () {
    // Header-injection defence in depth — Response::redirect also strips
    // these, but a stored target_url should never be saved with them.
    assert_true(!ShortLinkRepository::isAllowedUrl("https://example.com\r\nX-Injected: 1"));
    assert_true(!ShortLinkRepository::isAllowedUrl("https://example.com\nX-Injected"));
    assert_true(!ShortLinkRepository::isAllowedUrl("https://example.com\0"));
});

it('ShortLinkRepository::generateSlug yields a base64url-shaped 8-char string', function () {
    $slug = ShortLinkRepository::generateSlug();
    assert_eq(8, strlen($slug));
    assert_true((bool)preg_match('/^[A-Za-z0-9_-]+$/', $slug));
});

it('ShortLinkRepository create + findBySlug + findActiveBySlug', function () {
    $repo = new ShortLinkRepository(_slPdo());
    $id   = $repo->create('https://example.com', 'Demo', 42);
    assert_true($id > 0);
    $row = $repo->findById($id);
    assert_eq('https://example.com', $row['target_url']);
    assert_eq('Demo', $row['title']);
    assert_eq(0, (int)$row['is_disabled']);

    $bySlug = $repo->findBySlug($row['slug']);
    assert_eq($id, (int)$bySlug['id']);

    // Disabled links don't surface in findActiveBySlug.
    $repo->update($id, ['is_disabled' => 1]);
    assert_eq(null, $repo->findActiveBySlug($row['slug']));
});

it('ShortLinkRepository rotateSlug changes slug and old slug stops resolving', function () {
    $repo = new ShortLinkRepository(_slPdo());
    $id   = $repo->create('https://example.com', null, 1);
    $row  = $repo->findById($id);
    $oldSlug = $row['slug'];
    $newSlug = $repo->rotateSlug($id);
    assert_true($newSlug !== $oldSlug);
    assert_eq(null, $repo->findBySlug($oldSlug));
    assert_eq($id, (int)$repo->findBySlug($newSlug)['id']);
});

it('ShortLinkRepository::listForUser scopes by ownership unless admin', function () {
    $repo = new ShortLinkRepository(_slPdo());
    $repo->create('https://a.com', null, 1);
    $repo->create('https://b.com', null, 2);
    $repo->create('https://c.com', null, 1);
    assert_eq(3, count($repo->listForUser(1, true)));         // admin sees all
    assert_eq(2, count($repo->listForUser(1, false)));        // owner sees own only
    assert_eq(1, count($repo->listForUser(2, false)));
});

it('ShortLinkVisitRepository::hashIp is stable + null-safe', function () {
    $h1 = ShortLinkVisitRepository::hashIp('1.2.3.4', 'secret');
    $h2 = ShortLinkVisitRepository::hashIp('1.2.3.4', 'secret');
    assert_eq($h1, $h2);
    $h3 = ShortLinkVisitRepository::hashIp('1.2.3.5', 'secret');
    assert_true($h1 !== $h3);
    assert_eq(null, ShortLinkVisitRepository::hashIp('', 'secret'));
    assert_eq(null, ShortLinkVisitRepository::hashIp('not-an-ip', 'secret'));
});

it('ShortLinkVisitRepository counts total + unique correctly', function () {
    $pdo = _slPdo();
    $links  = new ShortLinkRepository($pdo);
    $visits = new ShortLinkVisitRepository($pdo);
    $id = $links->create('https://example.com', null, 1);

    $visits->log($id, 'hashA', 'UA1', null);
    $visits->log($id, 'hashA', 'UA1', null);   // same visitor → unique = 1
    $visits->log($id, 'hashB', 'UA2', null);
    $visits->log($id, null,    'UA3', null);   // NULL hash → excluded from unique

    assert_eq(4, $visits->countTotal($id));
    // 2 distinct non-null ip_hash values; the anonymous row doesn't count.
    assert_eq(2, $visits->countUnique($id));
});

it('ShortLinkVisitRepository::countByDay returns N rows oldest first', function () {
    $pdo = _slPdo();
    $links  = new ShortLinkRepository($pdo);
    $visits = new ShortLinkVisitRepository($pdo);
    $id = $links->create('https://example.com', null, 1);
    $visits->log($id, 'hashA', null, null);
    $days = $visits->countByDay($id, 7);
    assert_eq(7, count($days));
    assert_true(strcmp($days[0]['date'], $days[6]['date']) <= 0);
    // The most recent day should have total >= 1.
    assert_true($days[6]['total'] >= 1);
});

it('ShortLinkVisitRepository::topReferers buckets by host with "direct" fallback', function () {
    $pdo = _slPdo();
    $links  = new ShortLinkRepository($pdo);
    $visits = new ShortLinkVisitRepository($pdo);
    $id = $links->create('https://example.com', null, 1);
    $visits->log($id, 'a', null, 'https://twitter.com/post/1');
    $visits->log($id, 'b', null, 'https://twitter.com/post/2');
    $visits->log($id, 'c', null, null);
    $top = $visits->topReferers($id, 5);
    // twitter.com should be first with 2 hits.
    assert_eq('twitter.com', $top[0]['host']);
    assert_eq(2, $top[0]['count']);
});
