<?php
declare(strict_types=1);

it('inline_style emits style attribute with the per-request nonce', function () {
    $out = inline_style('color: red');
    assert_true(str_contains($out, 'style="color: red"'));
    assert_true(preg_match('/nonce="[A-Za-z0-9+\/=]{20,}"/', $out) === 1);
});

it('inline_style escapes embedded double quotes', function () {
    $out = inline_style('content: "x"');
    assert_true(!str_contains($out, 'content: "x"'));
    assert_true(str_contains($out, '&quot;'));
});

it('inline_style escapes & to keep URLs and entities safe', function () {
    $out = inline_style("background: url('a&b.png')");
    assert_true(!preg_match('/=\s*"[^"]*&[^a#]/', $out), 'bare & in CSS body');
    assert_true(str_contains($out, '&amp;'));
});

it('inline_style embeds the same nonce as csp_nonce()', function () {
    $out = inline_style('color: red');
    preg_match('/nonce="([^"]+)"/', $out, $m);
    assert_eq($m[1], csp_nonce());
});

it('inline_style strips newlines from CSS body', function () {
    $out = inline_style("a:1;\nb:2");
    assert_true(!str_contains($out, "\n"));
    assert_true(str_contains($out, 'a:1; b:2'));
});
