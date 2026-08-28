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
