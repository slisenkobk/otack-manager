<?php
declare(strict_types=1);

use App\Service\HtmlSanitizer;

it('allows safe tags', function () {
    $in  = '<p>Hello <strong>world</strong></p>';
    assert_eq($in, trim(HtmlSanitizer::clean($in)));
});

it('strips disallowed tags but keeps text', function () {
    $out = HtmlSanitizer::clean('<script>alert(1)</script><p>ok</p>');
    assert_true(strpos($out, '<script') === false, "script tag survived: $out");
    assert_true(strpos($out, 'ok') !== false, "body text lost: $out");
});

it('removes inline event handlers (onerror, onload, onclick)', function () {
    // <img> is not on the allow list — element is unwrapped, attrs go with it.
    // Use an allowed tag (<a>) to specifically verify on* removal on a tag we keep.
    $out = HtmlSanitizer::clean('<a href="https://example.com" onclick="alert(1)">x</a>');
    assert_true(strpos($out, 'onclick') === false, "onclick leaked: $out");
});

it('strips javascript: hrefs', function () {
    $out = HtmlSanitizer::clean('<a href="javascript:alert(1)">click</a>');
    assert_true(strpos($out, 'javascript:') === false, "javascript: leaked: $out");
});

it('strips data: hrefs', function () {
    $out = HtmlSanitizer::clean('<a href="data:text/html,<script>alert(1)</script>">click</a>');
    assert_true(strpos($out, 'data:') === false, "data: leaked: $out");
});

it('allows mailto: hrefs', function () {
    $out = HtmlSanitizer::clean('<a href="mailto:user@example.com">email</a>');
    assert_true(strpos($out, 'mailto:user@example.com') !== false, "mailto stripped: $out");
});

it('allows https: hrefs', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com">site</a>');
    assert_true(strpos($out, 'https://example.com') !== false, "https stripped: $out");
});

it('adds rel=noopener noreferrer to target=_blank links', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com" target="_blank">click</a>');
    assert_true(strpos($out, 'noopener') !== false, "rel noopener missing: $out");
});

it('preserves nested allowed tags', function () {
    $in  = '<p>Hello <strong>bold <em>italic</em></strong></p>';
    $out = HtmlSanitizer::clean($in);
    assert_true(strpos($out, '<em>') !== false, "em lost: $out");
    assert_true(strpos($out, '<strong>') !== false, "strong lost: $out");
});

it('returns empty for empty input', function () {
    assert_eq('', trim(HtmlSanitizer::clean('')));
});

it('preserves unicode content', function () {
    $out = HtmlSanitizer::clean('<p>Привіт 你好</p>');
    assert_true(strpos($out, 'Привіт') !== false, "cyrillic lost: $out");
    assert_true(strpos($out, '你好') !== false, "cjk lost: $out");
});

it('removes HTML comments', function () {
    $out = HtmlSanitizer::clean('<p>x</p><!-- secret --><p>y</p>');
    assert_true(strpos($out, 'secret') === false, "comment leaked: $out");
});

it('strips onmouseover from allow-listed <a>', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com" onmouseover="alert(1)">x</a>');
    assert_true(strpos($out, 'onmouseover') === false, "onmouseover leaked: $out");
});

it('strips onload from allow-listed <a>', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com" onload="alert(1)">x</a>');
    assert_true(strpos($out, 'onload') === false, "onload leaked: $out");
});

it('strips onfocus from allow-listed <a>', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com" onfocus="alert(1)">x</a>');
    assert_true(strpos($out, 'onfocus') === false, "onfocus leaked: $out");
});

it('strips onerror from allow-listed <a>', function () {
    $out = HtmlSanitizer::clean('<a href="https://example.com" onerror="alert(1)">x</a>');
    assert_true(strpos($out, 'onerror') === false, "onerror leaked: $out");
});
