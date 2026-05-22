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
