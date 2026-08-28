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

// --- XSS sweep: unwrapping a disallowed element used to promote its children
// into the parent without ever re-checking them, so one wrapper in a tag that
// is not on the allow list smuggled any payload straight through. These pin
// the recursive-unwrap behaviour.

it('sanitises children promoted out of a disallowed wrapper', function () {
    $out = HtmlSanitizer::clean('<section><img src=x onerror="alert(1)"></section>');
    assert_true(stripos($out, 'onerror') === false, "onerror survived one wrapper: $out");
    assert_true(stripos($out, '<img') === false, "img survived one wrapper: $out");
});

it('sanitises children promoted out of nested disallowed wrappers', function () {
    $out = HtmlSanitizer::clean('<section><article><img src=x onerror="alert(1)"></article></section>');
    assert_true(stripos($out, 'onerror') === false, "onerror survived two wrappers: $out");
    assert_true(stripos($out, '<img') === false, "img survived two wrappers: $out");
});

it('strips a <script> nested inside a disallowed wrapper but keeps its text', function () {
    $out = HtmlSanitizer::clean('<span><script>alert(1)</script></span>');
    assert_true(stripos($out, '<script') === false, "script survived a wrapper: $out");
});

it('is idempotent — cleaning twice equals cleaning once', function () {
    $in  = '<section><article><img src=x onerror="alert(1)"></article></section><p>ok</p>';
    $once = HtmlSanitizer::clean($in);
    assert_eq($once, HtmlSanitizer::clean($once), "not idempotent: $once");
});

it('cleanRich also sanitises promoted children', function () {
    $out = HtmlSanitizer::cleanRich('<section><img src="javascript:alert(1)" onerror="alert(2)"></section>');
    assert_true(stripos($out, 'onerror') === false, "onerror survived in rich mode: $out");
    assert_true(stripos($out, 'javascript:') === false, "javascript: src survived in rich mode: $out");
});

// --- Quill 2 emits a code block as one <div> per line inside a container
// <div>. `div` is allow-listed (attributes stripped) precisely so those line
// boxes survive; dropping them would splice every line into one run of text.

it('keeps Quill code-block line structure but strips its attributes', function () {
    $out = HtmlSanitizer::clean(
        '<div class="ql-code-block-container" spellcheck="false">'
        . '<div class="ql-code-block">a();</div><div class="ql-code-block">b();</div></div>'
    );
    assert_eq('<div><div>a();</div><div>b();</div></div>', $out);
});

it('strips event handlers from an allow-listed <div>', function () {
    $out = HtmlSanitizer::clean('<div onclick="alert(1)" style="x">t</div>');
    assert_eq('<div>t</div>', $out);
});

it('preserves the rest of the Quill toolbar output verbatim', function () {
    foreach ([
        '<p><strong>b</strong> <em>i</em> <u>u</u></p>',
        '<p><code>x = 1</code></p>',
        '<pre>a();</pre>',
        '<ol><li>one</li><li>two</li></ol>',
        '<ul><li>one</li><li>two</li></ul>',
        '<p><br></p>',
        '<blockquote>q</blockquote>',
    ] as $in) {
        assert_eq($in, HtmlSanitizer::clean($in), "Quill markup mangled: $in");
    }
});
