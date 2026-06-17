<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\KnowledgeCategoryRepository;
use App\Repository\KnowledgePageRepository;
use App\Repository\KnowledgePageVersionRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;

function _new_knowledge_setup(): array {
    $db = sys_get_temp_dir() . '/otack-kb-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    return [$pdo, $db];
}

it('KnowledgeCategoryRepository::create assigns sort_order monotonically', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $cats = new KnowledgeCategoryRepository($pdo);
    $a = $cats->create('Engineering');
    $b = $cats->create('HR');
    $rows = $cats->listAll();
    assert_eq(2, count($rows));
    assert_eq('engineering', $rows[0]['slug']);
    assert_eq('hr',          $rows[1]['slug']);
    assert_true((int)$rows[0]['sort_order'] < (int)$rows[1]['sort_order']);
    @unlink($db);
});

it('KnowledgeCategoryRepository::slugify gives -2/-3 suffix on collision', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $cats = new KnowledgeCategoryRepository($pdo);
    $cats->create('Docs');
    $cats->create('Docs');
    $cats->create('Docs');
    $rows = $cats->listAll();
    $slugs = array_map(fn($r) => $r['slug'], $rows);
    sort($slugs);
    assert_eq(['docs', 'docs-2', 'docs-3'], $slugs);
    @unlink($db);
});

it('KnowledgePageRepository::create + findBySlug round-trips title/body/category', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $users = new UserRepository($pdo);
    $author = $users->create('a@x', 'h', 'Author');
    $cats = new KnowledgeCategoryRepository($pdo);
    $cid = $cats->create('Engineering');
    $pages = new KnowledgePageRepository($pdo);
    $pid = $pages->create('Onboarding Guide', "# Hello\n\nWelcome.", $cid, $author);
    $p = $pages->findBySlug('onboarding-guide');
    assert_eq($pid, (int)$p['id']);
    assert_eq('Onboarding Guide', $p['title']);
    assert_eq("# Hello\n\nWelcome.", $p['body_md']);
    assert_eq($cid, (int)$p['category_id']);
    assert_eq('Engineering', $p['category_name']);
    assert_eq('Author', $p['author_name']);
    @unlink($db);
});

it('KnowledgePageRepository::listFiltered narrows by category, tag, and q', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $users = new UserRepository($pdo);
    $author = $users->create('a@x', 'h', 'A');
    $cats = new KnowledgeCategoryRepository($pdo);
    $eng = $cats->create('Engineering');
    $hr  = $cats->create('HR');
    $pages = new KnowledgePageRepository($pdo);
    $p1 = $pages->create('Deploy runbook',  'k8s rolling',  $eng, $author);
    $p2 = $pages->create('Holiday policy',  'days off',     $hr,  $author);
    $p3 = $pages->create('Code review',     'PR checklist', $eng, $author);
    $tags = new TagRepository($pdo);
    $tid = $tags->create('knowledge_page', 'critical');
    $tags->attachToKnowledgePage($p1, $tid);

    // No filters → 3
    $all = $pages->listFiltered(null, null, '', 1, 20);
    assert_eq(3, $all['total']);

    // Category Engineering → 2
    $byCat = $pages->listFiltered($eng, null, '', 1, 20);
    assert_eq(2, $byCat['total']);

    // Tag critical → 1
    $byTag = $pages->listFiltered(null, $tid, '', 1, 20);
    assert_eq(1, $byTag['total']);
    assert_eq('Deploy runbook', $byTag['rows'][0]['title']);

    // Query 'review' (title) → 1
    $byQ = $pages->listFiltered(null, null, 'review', 1, 20);
    assert_eq(1, $byQ['total']);

    // Combined: Engineering + 'runbook' → 1
    $combined = $pages->listFiltered($eng, null, 'runbook', 1, 20);
    assert_eq(1, $combined['total']);
    @unlink($db);
});

it('KnowledgePageVersionRepository::append is strictly additive', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $users = new UserRepository($pdo);
    $author = $users->create('a@x', 'h', 'A');
    $pages = new KnowledgePageRepository($pdo);
    $pid = $pages->create('Foo', 'v1', null, $author);

    $versions = new KnowledgePageVersionRepository($pdo);
    $v1 = $versions->append($pid, 'Foo', 'v1', 'first cut', $author);
    $v2 = $versions->append($pid, 'Foo', 'v2', null,        $author);
    $v3 = $versions->append($pid, 'Foo', 'v3', 'approved',  $author);

    assert_eq(3, $versions->countForPage($pid));
    $list = $versions->listForPage($pid);
    assert_eq(3, count($list));
    // Newest first
    assert_eq($v3, (int)$list[0]['id']);
    assert_eq($v1, (int)$list[2]['id']);
    // Snapshot keeps original body, doesn't follow live page edits
    $pages->update($pid, 'Foo (renamed)', 'v4', null);
    $rehydrated = $versions->findById($v1);
    assert_eq('v1', $rehydrated['body_md']);
    assert_eq('Foo', $rehydrated['title']);
    @unlink($db);
});

it('TagRepository attach/detach/listForKnowledgePage works through the new pivot', function () {
    [$pdo, $db] = _new_knowledge_setup();
    $users = new UserRepository($pdo);
    $author = $users->create('a@x', 'h', 'A');
    $pages = new KnowledgePageRepository($pdo);
    $pid = $pages->create('Foo', '', null, $author);

    $tags = new TagRepository($pdo);
    $t1 = $tags->create('knowledge_page', 'urgent');
    $t2 = $tags->create('knowledge_page', 'fyi');

    $tags->attachToKnowledgePage($pid, $t1);
    $tags->attachToKnowledgePage($pid, $t2);
    // Idempotent
    $tags->attachToKnowledgePage($pid, $t1);

    $list = $tags->listForKnowledgePage($pid);
    assert_eq(2, count($list));

    $tags->detachFromKnowledgePage($pid, $t1);
    $list = $tags->listForKnowledgePage($pid);
    assert_eq(1, count($list));
    assert_eq('fyi', $list[0]['name']);
    @unlink($db);
});

it('Markdown::renderRich sanitises raw HTML and dangerous links', function () {
    $out = \App\Service\Markdown::renderRich(
        "# Title\n\n<script>alert(1)</script>\n\n[bad](javascript:1) [ok](https://example.com)\n"
    );
    assert_true(!str_contains($out, '<script>'));
    // Headings now carry a slugified id="…" emitted by addHeadingAnchors;
    // assert text-content presence + id, not exact tag spelling.
    assert_true((bool)preg_match('#<h1 id="title">Title</h1>#', $out));
    assert_true(str_contains($out, 'https://example.com'));
    // javascript: link gets href stripped by HtmlSanitizer
    assert_true(!preg_match('/href="javascript/i', $out));
});

it('Markdown::renderRich emits unique anchor ids for repeated headings', function () {
    $out = \App\Service\Markdown::renderRich(
        "## Foo\n\n## Foo\n\n## Foo bar\n"
    );
    assert_true(str_contains($out, 'id="foo"'));
    assert_true(str_contains($out, 'id="foo-2"'));
    assert_true(str_contains($out, 'id="foo-bar"'));
});

it('Markdown::renderRich keeps Cyrillic anchors in id slugs', function () {
    $out = \App\Service\Markdown::renderRich("## Зміст\n");
    assert_true(str_contains($out, 'id="зміст"'));
});

it('HtmlSanitizer::cleanRich allows fragment-only anchor href for TOC links', function () {
    $out = \App\Service\HtmlSanitizer::cleanRich(
        '<p><a href="#section-1">jump</a></p>'
    );
    assert_true(str_contains($out, 'href="#section-1"'));
});
