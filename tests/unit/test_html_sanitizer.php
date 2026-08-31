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

it('drops a <script> nested inside a disallowed wrapper, source text included', function () {
    $out = HtmlSanitizer::clean('<span><script>alert(1)</script></span>');
    assert_true(stripos($out, '<script') === false, "script survived a wrapper: $out");
    assert_eq('', $out, "script source text leaked as prose: $out");
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

it('cleanRich() also keeps Quill code-block line structure via <div>', function () {
    // div was missing from ALLOWED_TAGS_RICH; task descriptions (Quill-
    // authored, same as project/comment descriptions) now use cleanRich(),
    // so this would have re-broken code-block line boxes for every task
    // description written in the web editor.
    $out = HtmlSanitizer::cleanRich(
        '<div class="ql-code-block-container" spellcheck="false">'
        . '<div class="ql-code-block">a();</div><div class="ql-code-block">b();</div></div>'
    );
    assert_eq('<div><div>a();</div><div>b();</div></div>', $out);
});

it('cleanRich() strips event handlers from an allow-listed <div>', function () {
    $out = HtmlSanitizer::cleanRich('<div onclick="alert(1)" style="x">t</div>');
    assert_eq('<div>t</div>', $out);
});

it('cleanRich() allow-list is a strict superset of clean()\'s', function () {
    // Feed input built only from ALLOWED_TAGS (the narrow list) through both
    // methods. If cleanRich() were missing any narrow-list tag, this input
    // would sanitise differently between the two — pinning the documented
    // "same XSS defences, wider tag whitelist" nesting relationship.
    $narrowSample = '<p>p</p><div>div</div><strong>strong</strong><em>em</em><u>u</u>'
        . '<a href="https://example.com">a</a><code>code</code><pre>pre</pre>'
        . '<ul><li>li</li></ul><ol><li>li</li></ol><br><blockquote>bq</blockquote>';
    assert_eq(
        HtmlSanitizer::clean($narrowSample),
        HtmlSanitizer::cleanRich($narrowSample),
        'cleanRich() dropped or altered a tag clean() keeps'
    );
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

// ---------------------------------------------------------------------------
// Round 2 — adversarial coverage.
//
// The round-1 rewrite stopped promoting an unwrapped element's children
// without re-checking them, but left one node type behind: libxml's HTML
// parser stores `<script>`/`<style>` content in an XML_CDATA_SECTION_NODE,
// which the walk treated as "text, keep as-is" — and `saveHTML()` writes a
// CDATA section back out verbatim, with no entity escaping. Unwrapping the
// disallowed wrapper therefore promoted live markup into the output.
// `<style>`/`<script>` and friends are now dropped with their content.
// ---------------------------------------------------------------------------

/**
 * Every payload exercised in this file. Used both by the targeted tests below
 * and by the blanket idempotency assertion at the bottom.
 *
 * @return list<string>
 */
function html_sanitizer_corpus(): array
{
    return [
        // --- baseline / benign
        '',
        '<p>Hello <strong>world</strong></p>',
        '<p>Привіт 你好</p>',
        '<p>x</p><!-- secret --><p>y</p>',
        '<div class="ql-code-block-container" spellcheck="false">'
            . '<div class="ql-code-block">a();</div><div class="ql-code-block">b();</div></div>',

        // --- raw-text elements with markup inside
        '<style><img src=x onerror=alert(1)></style>',
        '<style><meta http-equiv="refresh" content="0;url=https://evil.example"></style>',
        '<style><form action="https://evil.example" method="post"><input name="password"></form></style>',
        '<style>body{background:url(javascript:alert(1))}</style>',
        '<script><img src=x onerror=alert(1)></script>',
        '<script>alert("<b>hi</b>")</script>',
        '<textarea><img src=x onerror=alert(1)></textarea>',
        '<title><img src=x onerror=alert(1)></title>',
        '<xmp><img src=x onerror=alert(1)></xmp>',
        '<noembed><img src=x onerror=alert(1)></noembed>',
        '<noscript><img src=x onerror=alert(1)></noscript>',
        '<noframes><img src=x onerror=alert(1)></noframes>',
        '<iframe src="https://evil.example"><img src=x onerror=alert(1)></iframe>',

        // --- scheme obfuscation on href
        '<a href="&#106;avascript:alert(1)">x</a>',
        '<a href="&#x6a;avascript:alert(1)">x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>",
        "<a href=\"java\nscript:alert(1)\">x</a>",
        "<a href=\" javascript:alert(1)\">x</a>",
        "<a href=\"java\0script:alert(1)\">x</a>",
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="vbscript:msgbox(1)">x</a>',
        '<a href="//evil.com">x</a>',
        '<a href="data:text/html,<script>alert(1)</script>">x</a>',

        // --- root-relative URL fix (agreed 2026-08-27): "/path" is allowed,
        // "//host" and the backslash-normalisation trick are not
        '<a href="/projects/1">x</a>',
        '<img src="/uploads/2026/06/a.png">',
        '<a href="//evil.example">x</a>',
        '<img src="//evil.example/x.png">',
        "<a href=\"/\\evil.example\">x</a>",
        "<img src=\"/\\evil.example/x.png\">",
        '<a href="/%2Fevil.example">x</a>',

        // --- control-byte obfuscation of the same trick (2026-08-31): libxml
        // silently DROPS 0x01–0x08, 0x0B, 0x0C and 0x0E–0x1F when it
        // re-serialises an attribute, so a control byte wedged between the two
        // slashes hides "//host" from any predicate that reads the raw value.
        "<a href=\"/\x0B/evil.example\">x</a>",
        "<img src=\"/\x0C/evil.example/x.png\">",
        "<a href=\"/\x01\x02/evil.example\">x</a>",
        "<a href=\"/\x0B\\evil.example\">x</a>",
        "<img src=\"/\x1F/evil.example/x.png\">",
        "<a href=\"\t/\t/evil.example\">x</a>",
        "<a href=\"/\0/evil.example\">x</a>",

        // --- attributes beyond on*
        '<a href="https://ok.example" style="position:fixed;inset:0">x</a>',
        '<a href="https://ok.example" srcset="https://evil.example/x 1x">x</a>',
        '<a href="https://ok.example" formaction="https://evil.example">x</a>',
        '<a href="https://ok.example" xlink:href="javascript:alert(1)">x</a>',
        '<div style="background:url(javascript:alert(1))">t</div>',

        // --- foreign content
        '<svg><script>alert(1)</script></svg>',
        '<svg onload="alert(1)"><circle r="10"/></svg>',
        '<svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>',
        '<math><mtext><style><img src=x onerror=alert(1)></style></mtext></math>',
        '<math><maction actiontype="statusline#javascript:alert(1)">x</maction></math>',
        '<template><img src=x onerror=alert(1)></template>',
        '<template><p>inert</p></template>',

        // --- mXSS (classic DOMPurify-era vectors: markup that means one thing
        // to libxml and another to a browser's HTML parser)
        '<svg><style><!--</style>',
        '<svg><style><!--</style><img src=x onerror=alert(1)>',
        '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
        '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=1 onerror=alert(1)>">',
        '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
        '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
        '<svg><![CDATA[><image xlink:href="]]><img src=xx: onerror=alert(1)//"></svg>',
        '<table><style><img src=x onerror=alert(1)></style></table>',
        '<select><style><img src=x onerror=alert(1)></style></select>',
        '<listing><img src=x onerror=alert(1)></listing>',
        '<plaintext><img src=x onerror=alert(1)>',
        '<iframe srcdoc="&lt;img src=x onerror=alert(1)&gt;"></iframe>',
        '<style>@import url(javascript:alert(1))</style>',

        // --- case sensitivity
        '<SECTION ONCLICK="alert(1)"><P>hi</P></SECTION>',
        '<A HREF="JAVASCRIPT:alert(1)">x</A>',
        '<STYLE><IMG SRC=x ONERROR=alert(1)></STYLE>',

        // --- cursor edge cases
        '<p>a</p><section>last child</section>',
        '<p>a</p><section></section>',
        '<section></section>',
        '<p>a</p><style></style>',
        '<section><img src=x onerror=alert(1)></section>',
        '<section><article><img src=x onerror=alert(1)></article></section>',
    ];
}

/** Tags that must never appear in output, whichever allow list was used. */
const NEVER_LIVE = [
    '<meta', '<form', '<input', '<script', '<style', '<iframe',
    '<svg', '<math', '<template', '<object', '<embed', '<noscript',
];

/**
 * Assert no dangerous element or attribute survived.
 *
 * `<img>` is on the *rich* allow list (Knowledge-base Markdown renders
 * images), so it is only forbidden when checking `clean()` output — pass
 * $allowImg=true for `cleanRich()`. A surviving `<img>` there still carries
 * no `src` unless it is http(s)/data:image, and no `on*` handler.
 */
function assert_no_live(string $out, string $label, bool $allowImg = false): void
{
    $needles = NEVER_LIVE;
    if (!$allowImg) {
        $needles[] = '<img';
    }
    foreach ($needles as $needle) {
        assert_true(
            stripos($out, $needle) === false,
            "$label: live $needle survived => $out"
        );
    }
    assert_true(stripos($out, 'onerror') === false, "$label: onerror survived => $out");
    assert_true(stripos($out, 'onload') === false, "$label: onload survived => $out");
    assert_true(stripos($out, 'javascript:') === false, "$label: javascript: survived => $out");
    assert_true(stripos($out, 'vbscript:') === false, "$label: vbscript: survived => $out");
}

it('C1: <style> wrapping an <img onerror> yields no live img', function () {
    $out = HtmlSanitizer::clean('<style><img src=x onerror=alert(1)></style>');
    assert_eq('', $out, "style CDATA promoted raw: $out");
    assert_no_live($out, 'style/img');
});

it('C1: <style> wrapping a meta refresh yields no live meta', function () {
    $out = HtmlSanitizer::clean(
        '<style><meta http-equiv="refresh" content="0;url=https://evil.example"></style>'
    );
    assert_eq('', $out, "style CDATA promoted raw: $out");
    assert_true(stripos($out, 'evil.example') === false, "redirect target survived: $out");
});

it('C1: <style> wrapping a credential form yields no live form', function () {
    $out = HtmlSanitizer::clean(
        '<style><form action="https://evil.example" method="post"><input name="password"></form></style>'
    );
    assert_eq('', $out, "style CDATA promoted raw: $out");
    assert_true(stripos($out, 'password') === false, "form field survived: $out");
});

it('C1: cleanRich() closes the same CDATA hole', function () {
    foreach ([
        '<style><img src=x onerror=alert(1)></style>',
        '<style><meta http-equiv="refresh" content="0;url=https://evil.example"></style>',
        '<style><form action="https://evil.example" method="post"><input name="password"></form></style>',
    ] as $in) {
        $out = HtmlSanitizer::cleanRich($in);
        assert_no_live($out, "cleanRich($in)", true);
    }
});

it('C1: <style> text is dropped, not rendered as visible prose', function () {
    $out = HtmlSanitizer::clean('<p>keep</p><style>body{color:red}</style><p>me</p>');
    assert_eq('<p>keep</p><p>me</p>', $out);
});

it('C1: <script> text is dropped, not rendered as visible prose', function () {
    $out = HtmlSanitizer::clean('<p>keep</p><script>var secret = 1;</script><p>me</p>');
    assert_eq('<p>keep</p><p>me</p>', $out);
});

it('drops raw-text elements with markup inside', function () {
    foreach (['style', 'script', 'textarea', 'title', 'xmp', 'noembed', 'noscript', 'noframes'] as $tag) {
        $in  = "<$tag><img src=x onerror=alert(1)></$tag>";
        assert_no_live(HtmlSanitizer::clean($in), "raw-text <$tag>");
        assert_no_live(HtmlSanitizer::cleanRich($in), "raw-text rich <$tag>", true);
    }
});

it('rejects obfuscated href schemes', function () {
    foreach ([
        '<a href="&#106;avascript:alert(1)">x</a>',
        '<a href="&#x6a;avascript:alert(1)">x</a>',
        "<a href=\"java\tscript:alert(1)\">x</a>",
        "<a href=\"java\nscript:alert(1)\">x</a>",
        "<a href=\" javascript:alert(1)\">x</a>",
        "<a href=\"java\0script:alert(1)\">x</a>",
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="vbscript:msgbox(1)">x</a>',
        '<a href="//evil.com">x</a>',
    ] as $in) {
        $out = HtmlSanitizer::clean($in);
        assert_true(stripos($out, 'href') === false, "href survived for $in => $out");
    }
});

it('strips non-on* dangerous attributes', function () {
    foreach (['style', 'srcset', 'formaction', 'xlink:href'] as $attr) {
        $in  = '<a href="https://ok.example" ' . $attr . '="https://evil.example">x</a>';
        $out = HtmlSanitizer::clean($in);
        assert_true(stripos($out, $attr) === false, "$attr survived: $out");
        assert_true(stripos($out, 'evil.example') === false, "$attr value survived: $out");
    }
    assert_eq('<div>t</div>', HtmlSanitizer::clean('<div style="background:url(javascript:alert(1))">t</div>'));
});

it('neutralises foreign content (svg/math/foreignObject/template)', function () {
    foreach ([
        '<svg><script>alert(1)</script></svg>',
        '<svg onload="alert(1)"><circle r="10"/></svg>',
        '<svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>',
        '<math><mtext><style><img src=x onerror=alert(1)></style></mtext></math>',
        '<math><maction actiontype="statusline#javascript:alert(1)">x</maction></math>',
        '<template><img src=x onerror=alert(1)></template>',
    ] as $in) {
        assert_no_live(HtmlSanitizer::clean($in), "foreign $in");
        assert_no_live(HtmlSanitizer::cleanRich($in), "foreign rich $in", true);
    }
    // <template> content is inert in a browser; promoting it would make it live.
    assert_eq('', HtmlSanitizer::clean('<template><p>inert</p></template>'));
});

it('mXSS: <svg><style><!-- leaves no dangling comment', function () {
    $out = HtmlSanitizer::clean('<svg><style><!--</style>');
    assert_eq('', $out, "dangling comment opener survived: $out");
    $out2 = HtmlSanitizer::clean('<svg><style><!--</style><img src=x onerror=alert(1)>');
    assert_true(strpos($out2, '<!--') === false, "comment opener survived: $out2");
    assert_no_live($out2, 'mXSS svg/style');
});

it('handles mixed-case tags and attributes', function () {
    assert_eq('<p>hi</p>', HtmlSanitizer::clean('<SECTION ONCLICK="alert(1)"><P>hi</P></SECTION>'));
    assert_eq('<a>x</a>', HtmlSanitizer::clean('<A HREF="JAVASCRIPT:alert(1)">x</A>'));
    assert_eq('', HtmlSanitizer::clean('<STYLE><IMG SRC=x ONERROR=alert(1)></STYLE>'));
});

it('cursor: disallowed element as the last child', function () {
    assert_eq('<p>a</p>last child', HtmlSanitizer::clean('<p>a</p><section>last child</section>'));
    $out = HtmlSanitizer::clean('<p>a</p><section><img src=x onerror=alert(1)></section>');
    assert_eq('<p>a</p>', $out);
});

it('cursor: empty disallowed element', function () {
    assert_eq('<p>a</p>', HtmlSanitizer::clean('<p>a</p><section></section>'));
    assert_eq('', HtmlSanitizer::clean('<section></section>'));
    assert_eq('<p>a</p>', HtmlSanitizer::clean('<p>a</p><style></style>'));
    assert_eq('<p>a</p><p>b</p>', HtmlSanitizer::clean('<p>a</p><section></section><p>b</p>'));
});

it('clean() and cleanRich() are idempotent over the whole corpus', function () {
    foreach (html_sanitizer_corpus() as $in) {
        $once = HtmlSanitizer::clean($in);
        assert_eq($once, HtmlSanitizer::clean($once), "clean() not idempotent for: $in");

        $onceRich = HtmlSanitizer::cleanRich($in);
        assert_eq($onceRich, HtmlSanitizer::cleanRich($onceRich), "cleanRich() not idempotent for: $in");
    }
});

it('no corpus payload yields a live img/meta/form/script/style', function () {
    foreach (html_sanitizer_corpus() as $in) {
        assert_no_live(HtmlSanitizer::clean($in), "corpus clean: $in");
    }
});

/**
 * Re-parse sanitised output the way a browser would and report only *live*
 * danger. Substring assertions cannot tell `onerror=alert(1)` from
 * `title="…&lt;img onerror=alert(1)&gt;"` — the second is inert text and a
 * legitimate output. This one parses instead of grepping.
 *
 * @return list<string>
 */
function live_dangers(string $out): array
{
    if ($out === '') return [];
    $doc = new \DOMDocument();
    @$doc->loadHTML('<html><body>' . $out . '</body></html>', \LIBXML_NOWARNING | \LIBXML_NOERROR);
    $bad = [];
    foreach ((new \DOMXPath($doc))->query('//*') as $el) {
        $tag = strtolower($el->tagName);
        if (in_array($tag, ['script', 'style', 'iframe', 'meta', 'form', 'input',
                            'object', 'embed', 'template', 'base', 'link', 'svg', 'math'], true)) {
            $bad[] = "live <$tag>";
        }
        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->name);
            if (str_starts_with($name, 'on')) {
                $bad[] = "live handler $name on <$tag>";
            }
            $val = str_replace(["\0", "\t", "\n", "\r"], '', $attr->value);
            if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)
                && preg_match('/^\s*(javascript|vbscript)\s*:/i', $val)) {
                $bad[] = "live scheme in $name";
            }
            // Off-origin authority. A value that begins "//host" or "/\host"
            // is a protocol-relative *absolute* URL to another origin — the
            // <a> navigation is not covered by any CSP directive (form-action
            // governs form submission; there is no navigate-to). Browsers
            // strip leading/trailing ASCII whitespace and ignore C0 controls
            // in URL attributes, and libxml silently drops most C0 bytes when
            // it re-serialises, so normalise both away before looking. "%2F"
            // and "%5C" are NOT decoded into an authority separator by the
            // WHATWG URL parser, so an encoded slash stays same-origin and
            // must not be flagged.
            if (in_array($name, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)) {
                $origin = preg_replace('/[\x00-\x20\x7F]+/', '', $attr->value);
                if (preg_match('#^/[/\\\\]#', $origin)) {
                    $bad[] = "off-origin authority in $name";
                }
            }
        }
    }
    return $bad;
}

it('no corpus payload produces a live element or handler when re-parsed', function () {
    foreach (html_sanitizer_corpus() as $in) {
        foreach (['clean', 'cleanRich'] as $method) {
            $out = HtmlSanitizer::$method($in);
            // Also check a second sanitise generation — an mXSS payload that
            // survives escaped must not come back live on the next pass.
            $dangers = array_unique(array_merge(
                live_dangers($out),
                live_dangers(HtmlSanitizer::$method($out))
            ));
            assert_eq([], array_values($dangers), "$method('$in') => '$out'");
        }
    }
});

// ---------------------------------------------------------------------------
// Heading anchors: allowed for the wiki, denied to task descriptions.
//
// `id` was on the rich allow-list because `Markdown::renderRich()` generates
// every heading id itself from slugified heading text, behind Parsedown's
// safe mode. Task descriptions moved onto the same list but have no Parsedown
// in front — the author picks the id byte-for-byte. That is not XSS, it is DOM
// clobbering: views/layouts/main.php emits page content *before* #modal-root,
// #toast-root, #lightbox-root and #i18n-js, so a planted duplicate id wins
// getElementById. It works with CSP fully enabled.
// ---------------------------------------------------------------------------

it('cleanRich() emits no id on any tag', function () {
    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $h) {
        $out = HtmlSanitizer::cleanRich("<$h id=\"i18n-js\">x</$h>");
        assert_true(stripos($out, 'id=') === false, "id survived on <$h>: $out");
        assert_true(str_contains($out, 'x'), "heading text lost: $out");
    }
    foreach (['<p id="modal-root">x</p>', '<div id="toast-root">x</div>',
              '<td id="task-description-hidden">x</td>', '<img src="https://e.example/a.png" id="i18n-js">'] as $in) {
        assert_true(stripos(HtmlSanitizer::cleanRich($in), 'id=') === false, "id survived: $in");
    }
});

it('cleanRich() cannot clobber the app-shell element ids', function () {
    // The two ids the reviewer landed exploits on.
    foreach (['i18n-js', 'task-description-hidden'] as $id) {
        $out = HtmlSanitizer::cleanRich('<h2 id="' . $id . '">{"js.toast.saved":"spoofed"}</h2>');
        assert_true(!str_contains($out, $id), "clobbering id '$id' survived: $out");
    }
});

it('cleanRichWithAnchors() keeps heading ids — and only on headings', function () {
    $out = HtmlSanitizer::cleanRichWithAnchors('<h2 id="section-1">Section</h2>');
    assert_true(str_contains($out, 'id="section-1"'), "wiki heading anchor lost: $out");

    // Widening the list must not have leaked `id` onto anything else.
    foreach (['<p id="x">a</p>', '<div id="x">a</div>', '<td id="x">a</td>',
              '<img src="https://e.example/a.png" id="x">'] as $in) {
        assert_true(stripos(HtmlSanitizer::cleanRichWithAnchors($in), 'id=') === false, "id leaked: $in");
    }
});

it('cleanRichWithAnchors() differs from cleanRich() only by heading ids', function () {
    foreach (html_sanitizer_corpus() as $in) {
        $rich    = HtmlSanitizer::cleanRich($in);
        $anchors = HtmlSanitizer::cleanRichWithAnchors($in);
        // No corpus payload carries a heading id, so the two must agree.
        assert_eq($rich, $anchors, "allow-lists diverged for: $in");
    }
});

it('cleanRichWithAnchors() is idempotent and still blocks everything cleanRich() blocks', function () {
    foreach (html_sanitizer_corpus() as $in) {
        $once = HtmlSanitizer::cleanRichWithAnchors($in);
        assert_eq($once, HtmlSanitizer::cleanRichWithAnchors($once), "not idempotent for: $in");
        assert_eq([], array_values(live_dangers($once)), "cleanRichWithAnchors('$in') => '$once'");
    }
});

it('Markdown::renderRich() still emits heading anchors after the split', function () {
    $out = \App\Service\Markdown::renderRich("## Table of contents\n\n- [Intro](#intro)\n\n## Intro\n");
    assert_true(str_contains($out, 'id="table-of-contents"'), "wiki anchor lost: $out");
    assert_true(str_contains($out, 'id="intro"'), "wiki anchor lost: $out");
    assert_true(str_contains($out, 'href="#intro"'), "TOC link lost: $out");
});

// ---------------------------------------------------------------------------
// M-1: processing instructions.
// ---------------------------------------------------------------------------

it('drops processing instructions instead of passing them through', function () {
    $pi = '<' . '?php system($_GET[0]); ?' . '>';
    foreach (['clean', 'cleanRich', 'cleanRichWithAnchors'] as $method) {
        $out = HtmlSanitizer::$method('<p>a</p>' . $pi . '<p>b</p>');
        assert_eq('<p>a</p><p>b</p>', $out, "$method() kept a PI: $out");
    }
    // Nested inside a kept element, and inside an unwrapped one.
    assert_eq('<p>ab</p>', HtmlSanitizer::clean('<p>a' . $pi . 'b</p>'));
    assert_eq('<p>ab</p>', HtmlSanitizer::clean('<section><p>a' . $pi . 'b</p></section>'));

    $xsl = '<' . '?xml-stylesheet href="x.xsl"?' . '>';
    assert_eq('<p>a</p>', HtmlSanitizer::clean('<p>a</p>' . $xsl));
    // Same policy as comments — content goes with the node.
    assert_eq('<p>a</p>', HtmlSanitizer::clean('<p>a</p><!-- keep me out -->'));
});

// ---------------------------------------------------------------------------
// Rich-only attributes as injection vectors. `clean()` never exposed an
// attribute that can hold arbitrary author text next to a URL; `cleanRich()`
// exposes four (img[alt], img[title], th[align], td[align]). Their *values*
// must stay inert text no matter what is in them.
// ---------------------------------------------------------------------------

it('rich-only attribute values stay inert text', function () {
    $breakouts = [
        '" onerror="alert(1)',
        '\' onerror=\'alert(1)',
        '"><img src=x onerror=alert(1)>',
        '</td><script>alert(1)</script>',
        'x https://e.example/onerror=alert(1)// y',
    ];
    foreach ($breakouts as $v) {
        $cases = [
            '<img src="https://e.example/a.png" alt="' . $v . '">',
            '<img src="https://e.example/a.png" title="' . $v . '">',
            '<table><tr><th align="' . $v . '">h</th></tr></table>',
            '<table><tr><td align="' . $v . '">c</td></tr></table>',
        ];
        foreach ($cases as $in) {
            foreach (['cleanRich', 'cleanRichWithAnchors'] as $method) {
                $out = HtmlSanitizer::$method($in);
                assert_eq([], array_values(live_dangers($out)), "$method('$in') => '$out'");
                assert_eq(
                    [],
                    array_values(live_dangers(\App\Service\LinkPreview::enhance($out))),
                    "$method + enhance ('$in') => '$out'"
                );
            }
        }
    }
});

// ---------------------------------------------------------------------------
// Fix 2 (agreed 2026-08-27) — root-relative URLs ("/path", a single leading
// slash) are additionally allowed for href/src, so internal links and
// uploaded images survive sanitisation. "//host/path" is protocol-relative
// (an absolute URL to another origin) and must stay blocked — a naive `^/`
// would reopen exactly the hole this allow-list exists to close.
// ---------------------------------------------------------------------------

it('allows root-relative href', function () {
    $out = HtmlSanitizer::clean('<a href="/projects/1">x</a>');
    assert_true(strpos($out, 'href="/projects/1"') !== false, "root-relative href stripped: $out");
});

it('allows root-relative src', function () {
    $out = HtmlSanitizer::cleanRich('<img src="/uploads/2026/06/a.png">');
    assert_true(strpos($out, 'src="/uploads/2026/06/a.png"') !== false, "root-relative src stripped: $out");
});

it('still blocks protocol-relative //host as href', function () {
    $out = HtmlSanitizer::clean('<a href="//evil.example">x</a>');
    assert_true(strpos($out, 'href') === false, "protocol-relative href survived: $out");
});

it('still blocks protocol-relative //host as src', function () {
    $out = HtmlSanitizer::cleanRich('<img src="//evil.example/x.png">');
    assert_true(strpos($out, 'src') === false, "protocol-relative src survived: $out");
});

it('blocks backslash-after-slash href (browsers normalise \\ to / for special schemes, making it protocol-relative)', function () {
    $out = HtmlSanitizer::clean('<a href="/\\evil.example">x</a>');
    assert_true(strpos($out, 'href') === false, "backslash-obfuscated protocol-relative href survived: $out");
});

it('blocks backslash-after-slash src', function () {
    $out = HtmlSanitizer::cleanRich('<img src="/\\evil.example/x.png">');
    assert_true(strpos($out, 'src') === false, "backslash-obfuscated protocol-relative src survived: $out");
});

it('allows a same-origin path containing an encoded slash (%2F is not decoded by the URL parser into a host separator)', function () {
    $out = HtmlSanitizer::clean('<a href="/%2Fevil.example">x</a>');
    assert_true(strpos($out, 'href="/%2Fevil.example"') !== false, "encoded-slash same-origin href stripped: $out");
});

it('does not additionally allow ./, ../, or bare relative paths', function () {
    foreach (['./x', '../x', 'x/y', 'projects/1'] as $val) {
        $out = HtmlSanitizer::clean('<a href="' . $val . '">x</a>');
        assert_true(strpos($out, 'href') === false, "non-root-relative href unexpectedly survived for '$val': $out");
    }
});

// ---------------------------------------------------------------------------
// Regression: the checked string must be the string that reaches the browser.
//
// libxml re-serialises attribute values on output and does NOT round-trip
// every byte: 0x01–0x08, 0x0B, 0x0C and 0x0E–0x1F are silently DROPPED, and
// 0x00 TRUNCATES the value. Only tab/LF/CR/space/0x7F survive, as %XX. So a
// predicate that inspects the raw value can approve "/<0x0B>/evil.example"
// — "/" not followed by "/" — and then serialise "//evil.example", a
// protocol-relative URL to another origin. These pin the whole byte family.
// ---------------------------------------------------------------------------

/** Bytes libxml silently drops from an attribute value (28 of them). */
function libxml_dropped_bytes(): array
{
    return array_merge(range(0x01, 0x08), [0x0B, 0x0C], range(0x0E, 0x1F));
}

it('blocks C0-control-obfuscated protocol-relative href (libxml drops the byte on output)', function () {
    foreach (libxml_dropped_bytes() as $b) {
        $hex = sprintf('0x%02X', $b);
        $out = HtmlSanitizer::clean('<a href="/' . chr($b) . '/evil.example">x</a>');
        assert_true(strpos($out, 'href') === false,
            "control-byte $hex synthesised an off-origin href: $out");
        assert_eq([], live_dangers($out), "control-byte $hex href => $out");
    }
});

it('blocks C0-control-obfuscated protocol-relative src (libxml drops the byte on output)', function () {
    foreach (libxml_dropped_bytes() as $b) {
        $hex = sprintf('0x%02X', $b);
        $out = HtmlSanitizer::cleanRich('<img src="/' . chr($b) . '/evil.example/x.png">');
        assert_true(strpos($out, 'src') === false,
            "control-byte $hex synthesised an off-origin src: $out");
        assert_eq([], live_dangers($out), "control-byte $hex src => $out");
    }
});

it('blocks the C0-control + backslash variant (/<ctl>\\host)', function () {
    foreach (libxml_dropped_bytes() as $b) {
        $hex = sprintf('0x%02X', $b);
        $out = HtmlSanitizer::clean('<a href="/' . chr($b) . '\\evil.example">x</a>');
        assert_true(strpos($out, 'href') === false,
            "control-byte $hex + backslash survived: $out");
        assert_eq([], live_dangers($out), "control-byte $hex backslash href => $out");
    }
});

it('blocks NUL-obfuscated protocol-relative href/src (libxml truncates at NUL during parsing)', function () {
    // libxml truncates an attribute value at the first NUL while *parsing*,
    // so the sanitiser only ever sees "/" here and the remainder never
    // reaches the document at all. Pin the outcome — same-origin, no host —
    // rather than the mechanism, so a libxml change that stops truncating
    // still has to satisfy the off-origin check below.
    $out = HtmlSanitizer::clean("<a href=\"/\0/evil.example\">x</a>");
    assert_true(strpos($out, 'evil.example') === false, "NUL-obfuscated href survived: $out");
    assert_eq([], live_dangers($out), "NUL href => $out");

    $out = HtmlSanitizer::cleanRich("<img src=\"/\0/evil.example/x.png\">");
    assert_true(strpos($out, 'evil.example') === false, "NUL-obfuscated src survived: $out");
    assert_eq([], live_dangers($out), "NUL src => $out");

    // NUL is also in the write-back strip set, so a value that *does* carry
    // one through to the predicate is normalised rather than silently cut.
    $out = HtmlSanitizer::clean("<a href=\"/proj\0ects/1\">x</a>");
    assert_true(strpos($out, 'evil') === false, "unexpected content: $out");
    assert_eq([], live_dangers($out), "NUL mid-path => $out");
});

it('blocks tab/LF/CR-obfuscated protocol-relative href/src (today safe only via %XX — pin it)', function () {
    foreach (["\t" => 'tab', "\n" => 'LF', "\r" => 'CR', ' ' => 'space'] as $c => $label) {
        $out = HtmlSanitizer::clean('<a href="/' . $c . '/evil.example">x</a>');
        assert_true(strpos($out, 'href') === false, "$label-obfuscated href survived: $out");
        assert_eq([], live_dangers($out), "$label href => $out");

        $out = HtmlSanitizer::cleanRich('<img src="/' . $c . '/evil.example/x.png">');
        assert_true(strpos($out, 'src') === false, "$label-obfuscated src survived: $out");
        assert_eq([], live_dangers($out), "$label src => $out");
    }
});

it('blocks leading-whitespace variants where trim() shifts the string before matching', function () {
    $payloads = [
        "\t/\t/evil.example",
        " / /evil.example",
        "\n/\n/evil.example",
        "\x0B/\x0B/evil.example",
        "\x0C//evil.example",
        "\x01/\x01/evil.example",
        "  /\x0B\x0C/evil.example",
    ];
    foreach ($payloads as $val) {
        $label = bin2hex($val);
        $out = HtmlSanitizer::clean('<a href="' . $val . '">x</a>');
        assert_true(strpos($out, 'href') === false, "leading-whitespace payload $label survived: $out");
        assert_eq([], live_dangers($out), "payload $label => $out");

        $out = HtmlSanitizer::cleanRich('<img src="' . $val . '">');
        assert_true(strpos($out, 'src') === false, "leading-whitespace payload $label survived on src: $out");
        assert_eq([], live_dangers($out), "payload $label src => $out");
    }
});

it('blocks multi-byte control combinations between the slashes', function () {
    $combos = ["\x01\x02", "\x0B\x0C", "\x1F\x0E", "\x0B\x09", "\x09\x0B", "\x0C\x20", "\x01\x0B\x0C\x1F"];
    foreach ($combos as $c) {
        $label = bin2hex($c);
        $out = HtmlSanitizer::clean('<a href="/' . $c . '/evil.example">x</a>');
        assert_true(strpos($out, 'href') === false, "combo $label survived on href: $out");
        assert_eq([], live_dangers($out), "combo $label href => $out");

        $out = HtmlSanitizer::cleanRich('<img src="/' . $c . '/evil.example/x.png">');
        assert_true(strpos($out, 'src') === false, "combo $label survived on src: $out");
        assert_eq([], live_dangers($out), "combo $label src => $out");
    }
});

it('blocks control-byte obfuscation of the javascript: scheme', function () {
    foreach (["java\x01script:alert(1)", "java\tscript:alert(1)", " javascript:alert(1)",
              "\x0Bjavascript:alert(1)", "j\x0Ba\x0Bv\x0Ba\x0Bscript:alert(1)"] as $val) {
        $out = HtmlSanitizer::clean('<a href="' . $val . '">x</a>');
        assert_true(strpos($out, 'href') === false, "obfuscated javascript: survived: $out");
        assert_eq([], live_dangers($out), "obfuscated scheme => $out");
    }
});

it('still accepts every legitimate URL shape after control-byte normalisation', function () {
    $hrefOk = [
        'https://ok.example/a?b=1#c',
        'http://ok.example/a',
        'mailto:someone@ok.example',
        '#fragment',
        '/projects/1',
        '/uploads/2026/06/a.png',
        '/%2Fevil.example',
    ];
    foreach ($hrefOk as $val) {
        $out = HtmlSanitizer::clean('<a href="' . $val . '">x</a>');
        assert_true(strpos($out, 'href="' . $val . '"') !== false,
            "legitimate href '$val' was stripped or mangled: $out");
    }

    $srcOk = [
        'https://ok.example/x.png',
        'data:image/png;base64,iVBORw0KGgo=',
        '/uploads/2026/06/a.png',
        '/%2Fevil.example',
    ];
    foreach ($srcOk as $val) {
        $out = HtmlSanitizer::cleanRich('<img src="' . $val . '">');
        assert_true(strpos($out, 'src="' . $val . '"') !== false,
            "legitimate src '$val' was stripped or mangled: $out");
    }
});

it('preserves a space inside an otherwise-legitimate path (percent-encoded, not deleted)', function () {
    $out = HtmlSanitizer::cleanRich('<img src="/uploads/a b.png">');
    assert_true(strpos($out, 'src="/uploads/a%20b.png"') !== false,
        "space in path was deleted rather than percent-encoded: $out");

    $out = HtmlSanitizer::clean('<a href="/uploads/a b.png">x</a>');
    assert_true(strpos($out, 'href="/uploads/a%20b.png"') !== false,
        "space in href path was deleted rather than percent-encoded: $out");
});

it('serialised value equals the value the predicate approved (no silently-dropped bytes survive storage)', function () {
    // A control byte anywhere in an accepted value must be gone from the
    // stored/serialised string too — otherwise "what was checked" and
    // "what is served" are different strings again.
    $out = HtmlSanitizer::clean("<a href=\"/proj\x0Bects/1\">x</a>");
    assert_true(strpos($out, 'href="/projects/1"') !== false,
        "normalised value not written back: $out");
    $out = HtmlSanitizer::cleanRich("<img src=\"https://ok.exa\x01mple/x.png\">");
    assert_true(strpos($out, 'src="https://ok.example/x.png"') !== false,
        "normalised value not written back on src: $out");
});
