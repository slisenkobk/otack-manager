<?php
declare(strict_types=1);

use App\Service\HtmlSanitizer;
use App\Service\LinkPreview;

/**
 * `HtmlSanitizer` is not the last thing that touches a task description on its
 * way to the page — `LinkPreview::enhance()` runs *after* it, in both render
 * paths (`views/tasks/show.php`, `TaskController::update()`'s
 * `description_html`). The composition is what the browser actually parses, so
 * that is what these tests assert. Nothing covered the composition before.
 *
 * The regression being locked down: `enhance()` used to auto-link bare URLs by
 * regexing the whole HTML string, attribute values included, and to splice a
 * raw `<a href="…" …>` in wherever it matched. A URL parked in an attribute the
 * rich allow-list permits therefore came back out as live attributes — `on*`
 * handlers included — on the host element. See `LinkPreview`'s class docblock.
 */

/**
 * Re-parse markup and return `['tag' => [attr names…], …]` for every element,
 * so a test can assert on what a parser sees rather than on substrings.
 *
 * @return array<int, array{tag: string, attrs: list<string>}>
 */
function lp_parse_elements(string $html): array
{
    if ($html === '') return [];
    $doc = new \DOMDocument();
    @$doc->loadHTML('<html><body>' . $html . '</body></html>', \LIBXML_NOWARNING | \LIBXML_NOERROR);
    $out = [];
    foreach ((new \DOMXPath($doc))->query('//body//*') as $el) {
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = strtolower($attr->name);
        }
        sort($names);
        $out[] = ['tag' => strtolower($el->tagName), 'attrs' => $names];
    }
    return $out;
}

/** Assert nothing in $html parses as an element carrying an `on*` handler. */
function lp_assert_no_handlers(string $html, string $label): void
{
    foreach (lp_parse_elements($html) as $el) {
        foreach ($el['attrs'] as $name) {
            assert_true(
                !str_starts_with($name, 'on'),
                "$label: live handler $name on <{$el['tag']}> => $html"
            );
        }
    }
}

/** Attribute names a parser sees on the first element with $tag, or null. */
function lp_attrs_of(string $html, string $tag): ?array
{
    foreach (lp_parse_elements($html) as $el) {
        if ($el['tag'] === $tag) return $el['attrs'];
    }
    return null;
}

/**
 * Every payload here hides a bare URL inside an attribute value that the rich
 * allow-list keeps. Pre-fix, `enhance()` rewrote each one into raw markup
 * inside the quoted value and the browser re-tokenised the URL's `/`-separated
 * segments into attributes on the host element.
 *
 * @return array<string, array{payload: string, tag: string, attrs: list<string>}>
 */
function lp_attribute_injection_corpus(): array
{
    $u = 'https://e.example/onmouseover=document.title=`PWNED`//';
    $e = 'https://e.example/onerror=document.title=`PWNED`//';
    return [
        // The reviewer's verified payload — writable by anyone passing
        // canEditTask (a task's assignee now qualifies) via PATCH /tasks/{id}.
        'h2[id]' => [
            'payload' => '<h2 id="hdr ' . $u . ' z">Findings</h2>',
            'tag'     => 'h2',
            // id is no longer on the rich allow-list at all (finding I-2), so
            // the heading reaches the page bare.
            'attrs'   => [],
        ],
        // The alt variant fires with no interaction: img-src 'self' data:
        // makes any external src fail, so onerror runs immediately.
        'img[alt]' => [
            'payload' => '<img src="https://ok.example/a.png" alt="x ' . $e . ' y">',
            'tag'     => 'img',
            'attrs'   => ['alt', 'src'],
        ],
        'img[title]' => [
            'payload' => '<img src="https://ok.example/a.png" title="x ' . $e . ' y">',
            'tag'     => 'img',
            'attrs'   => ['src', 'title'],
        ],
        'th[align]' => [
            'payload' => '<table><tr><th align="left ' . $u . ' z">h</th></tr></table>',
            'tag'     => 'th',
            'attrs'   => ['align'],
        ],
        'td[align]' => [
            'payload' => '<table><tr><td align="left ' . $u . ' z">c</td></tr></table>',
            'tag'     => 'td',
            'attrs'   => ['align'],
        ],
        // href is stashed by enhance() and was never the vector, but it is the
        // one attribute that legitimately holds a URL — keep it in the corpus
        // so a future refactor cannot quietly start rewriting inside it.
        'a[title]' => [
            'payload' => '<a href="https://ok.example/" title="see ' . $u . ' z">x</a>',
            'tag'     => 'a',
            'attrs'   => ['class', 'href', 'rel', 'spellcheck', 'target'],
        ],
    ];
}

it('enhance() never injects markup into an attribute value the rich allow-list keeps', function () {
    foreach (lp_attribute_injection_corpus() as $name => $case) {
        $sanitised = HtmlSanitizer::cleanRich($case['payload']);
        $enhanced  = LinkPreview::enhance($sanitised);

        lp_assert_no_handlers($enhanced, "cleanRich+enhance $name");

        $attrs = lp_attrs_of($enhanced, $case['tag']);
        assert_eq(
            $case['attrs'],
            $attrs,
            "cleanRich+enhance $name: unexpected attributes on <{$case['tag']}> => $enhanced"
        );

        // No link card may have been built out of an attribute value: the only
        // URLs in these payloads live inside attributes, so the card markup
        // must be absent entirely.
        assert_true(
            strpos($enhanced, 'link-card') === false || $case['tag'] === 'a',
            "cleanRich+enhance $name: a card was built from an attribute value => $enhanced"
        );
    }
});

it('enhance() leaves attribute-only URLs byte-identical to the sanitiser output', function () {
    // Strongest statement of the invariant: when every URL in the document sits
    // inside an attribute, enhance() is a no-op. Pre-fix each of these grew a
    // raw <a …> inside the quoted value.
    foreach (lp_attribute_injection_corpus() as $name => $case) {
        if ($case['tag'] === 'a') continue; // href upgrade is the intended behaviour
        $sanitised = HtmlSanitizer::cleanRich($case['payload']);
        assert_eq($sanitised, LinkPreview::enhance($sanitised), "enhance() rewrote an attribute for $name");
    }
});

it('enhance() does not inject even when heading ids are allowed (wiki allow-list)', function () {
    // Finding I-2 removed `id` from cleanRich(), which is what neutralises the
    // reviewer's h2 payload today. This asserts finding I-1 was fixed at the
    // root independently of that: with the anchor-bearing allow-list the id
    // survives verbatim, and enhance() still must not write into it.
    $payload = '<h2 id="hdr https://e.example/onmouseover=document.title=`PWNED`// z">Findings</h2>';
    $sanitised = HtmlSanitizer::cleanRichWithAnchors($payload);
    assert_true(str_contains($sanitised, 'onmouseover'), "precondition: id should survive => $sanitised");

    $enhanced = LinkPreview::enhance($sanitised);
    assert_eq($sanitised, $enhanced, 'enhance() rewrote a heading id');
    lp_assert_no_handlers($enhanced, 'cleanRichWithAnchors+enhance h2[id]');
    assert_eq(['id'], lp_attrs_of($enhanced, 'h2'), "unexpected attrs on <h2> => $enhanced");
});

it('enhance() still builds a card for a bare URL in ordinary text', function () {
    $out = LinkPreview::enhance('<p>see https://example.com/foo for more</p>');
    assert_true(str_contains($out, 'class="link-card"'), "no card built: $out");
    assert_true(str_contains($out, 'href="https://example.com/foo"'), "href lost: $out");
    assert_true(str_contains($out, '>example.com/foo<'), "pretty url lost: $out");
    assert_true(str_contains($out, 'see '), "surrounding prose lost: $out");
    assert_true(str_contains($out, ' for more'), "trailing prose lost: $out");
});

it('enhance() upgrades an existing plain anchor to a card, keeping its label', function () {
    $out = LinkPreview::enhance('<p><a href="https://example.com/foo">label</a></p>');
    assert_true(str_contains($out, 'class="link-card"'), "no card built: $out");
    assert_true(str_contains($out, '<span class="link-card__label">label</span>'), "label lost: $out");
    assert_true(str_contains($out, 'rel="noopener noreferrer"'), "rel lost: $out");
});

it('enhance() leaves anchors that are already cards, or wrap an image, alone', function () {
    $card = LinkPreview::enhance('<p>https://example.com/foo</p>');
    assert_eq($card, LinkPreview::enhance($card), 'enhance() is not idempotent over its own card');

    $imgLink = '<p><a href="https://example.com/foo"><img src="https://example.com/a.png" alt="a"></a></p>';
    assert_eq($imgLink, LinkPreview::enhance($imgLink), 'image link was rewritten');
});

it('enhance() leaves URLs inside <code>/<pre> verbatim', function () {
    foreach ([
        '<pre><code>https://example.com/x</code></pre>',
        '<p><code>https://example.com/x</code></p>',
        '<pre><a href="https://example.com/x">y</a></pre>',
    ] as $in) {
        assert_eq($in, LinkPreview::enhance($in), "code/pre content was rewritten: $in");
    }
});

it('enhance() returns non-URL input byte-for-byte', function () {
    foreach (['', '<p>plain text</p>', '<p><a href="/relative">x</a></p>', '<p>a &amp; b</p>'] as $in) {
        assert_eq($in, LinkPreview::enhance($in), "input mutated: $in");
    }
});

it('enhance() escapes card content instead of emitting it as markup', function () {
    // The label comes from the anchor's text content; it must be escaped, and
    // it must never be able to close the card's own attributes or tags.
    $out = LinkPreview::enhance('<p><a href="https://example.com/foo">&lt;img src=x onerror=alert(1)&gt;</a></p>');
    lp_assert_no_handlers($out, 'card label');
    assert_true(!str_contains($out, '<img'), "label became markup: $out");
});

it('the sanitiser + enhance() composition survives the whole adversarial corpus', function () {
    // The corpus is checked against the sanitiser alone elsewhere; run it
    // through the real render pipeline too, since that composition — not
    // cleanRich() on its own — is what reaches the DOM.
    foreach (html_sanitizer_corpus() as $in) {
        foreach (['cleanRich', 'cleanRichWithAnchors'] as $method) {
            $out = LinkPreview::enhance(HtmlSanitizer::$method($in));
            lp_assert_no_handlers($out, "$method+enhance('$in')");
            assert_true(
                !preg_match('/<(script|style|iframe|object|embed|template|meta|form|input|svg|math)\b/i', $out),
                "$method+enhance('$in') produced a live element => $out"
            );
        }
    }
});
