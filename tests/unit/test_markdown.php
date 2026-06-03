<?php
use App\Service\Markdown;

it('Markdown bold', function () {
    assert_eq('<p><strong>bold</strong></p>', Markdown::render('**bold**'));
});

it('Markdown inline code', function () {
    $out = Markdown::render('a `b` c');
    assert_true(str_contains($out, '<code>b</code>'));
});

it('Markdown valid link', function () {
    $out = Markdown::render('[ex](https://example.com)');
    assert_true(str_contains($out, '<a href="https://example.com" rel="noopener">ex</a>'));
});

it('Markdown rejects non-http link', function () {
    $out = Markdown::render('[ex](javascript:alert(1))');
    assert_true(!str_contains($out, '<a href'));
    assert_true(str_contains($out, '[ex](javascript:alert(1))'));
});

it('Markdown ignores single asterisks (no italic)', function () {
    $out = Markdown::render('*not italic*');
    assert_true(!str_contains($out, '<em>'));
    assert_true(!str_contains($out, '<i>'));
});

it('Markdown fenced code block', function () {
    $out = Markdown::render("```\nx = 1\n```");
    assert_true(str_contains($out, '<pre><code>'));
    assert_true(str_contains($out, 'x = 1'));
});

it('Markdown escapes script tags', function () {
    $out = Markdown::render('<script>x</script>');
    assert_true(!str_contains($out, '<script>'));
    assert_true(str_contains($out, '&lt;script&gt;'));
});

it('Markdown unordered list', function () {
    $out = Markdown::render("- a\n- b");
    assert_true(str_contains($out, '<ul>'));
    assert_true(str_contains($out, '<li>a</li>'));
    assert_true(str_contains($out, '<li>b</li>'));
});

it('Markdown ordered list', function () {
    $out = Markdown::render("1. a\n2. b");
    assert_true(str_contains($out, '<ol>'));
});

// --- Edge cases (T-4) ---------------------------------------------------------

it('Markdown nested bold inside bold collapses sanely', function () {
    // Parser is greedy non-greedy on **…**; the inner **inner** still produces
    // one strong span. The point: no raw asterisks remain dangling.
    $out = Markdown::render('**outer **inner** outer**');
    assert_true(str_contains($out, '<strong>'));
    assert_true(!str_contains($out, '**'));
});

it('Markdown link with data: scheme renders as plain text (no anchor)', function () {
    $out = Markdown::render('[ex](data:text/html,<script>)');
    assert_true(!str_contains($out, '<a href'));
    // entities are escaped at the outer pass; literal text retained
    assert_true(str_contains($out, '[ex](data:text/html'));
});

it('Markdown link with mailto: scheme is allowed', function () {
    $out = Markdown::render('[me](mailto:a@b.com)');
    assert_true(str_contains($out, '<a href="mailto:a@b.com"'));
});

it('Markdown fenced code block preserves leading whitespace inside', function () {
    $out = Markdown::render("```\n    indented\nx = 1\n```");
    assert_true(str_contains($out, '<pre><code>'));
    assert_true(str_contains($out, '    indented'));
});

it('Markdown inline <script> inside paragraph text is escaped', function () {
    $out = Markdown::render('hello <script>alert(1)</script> world');
    assert_true(!str_contains($out, '<script>'));
    assert_true(str_contains($out, '&lt;script&gt;'));
});

it('Markdown URL with & is emitted entity-encoded', function () {
    // The outer htmlspecialchars pass entity-encodes & before link parsing,
    // so the href ends up with &amp; rather than a raw ampersand.
    $out = Markdown::render('[q](https://example.com/?a=1&b=2)');
    assert_true(str_contains($out, '<a href="https://example.com/?a=1&amp;b=2"'));
});

it('Markdown does not treat # mid-sentence as a heading', function () {
    $out = Markdown::render('see issue #42 today');
    // No <h1>..<h6> emitted; renders as a paragraph
    assert_true(str_contains($out, '<p>'));
    assert_true(!preg_match('/<h[1-6]/', $out));
});

it('Markdown ordered list across blank line splits into two lists', function () {
    // Parser splits blocks on blank lines; two separate <ol> blocks expected.
    $out = Markdown::render("1. a\n2. b\n\n1. c\n2. d");
    assert_eq(2, substr_count($out, '<ol>'));
});
