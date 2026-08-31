<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Minimal HTML allow-list sanitiser.
 * Strips any tag not on the allow list and removes dangerous attributes
 * (event handlers, javascript: hrefs, data: urls).
 *
 * Used for Quill WYSIWYG output before persisting descriptions.
 *
 * Requires `ext-dom`. The extension is hard-required at boot
 * (see system/bootstrap.php); absence aborts startup with HTTP 500,
 * so this class can assume DOMDocument is always available.
 */
final class HtmlSanitizer
{
    /**
     * Tags whose textContent we keep but whose element we strip.
     *
     * `div` is on the list only to preserve line structure: Quill 2 renders a
     * code block as `<div class="ql-code-block-container">` wrapping one
     * `<div class="ql-code-block">` per line. Every attribute is stripped (the
     * `*` entry below is empty), so a kept `<div>` carries no class, style or
     * handler — it is a bare line box. Dropping it instead would splice every
     * code-block line into a single run of text.
     */
    private const ALLOWED_TAGS = [
        'p', 'div', 'strong', 'em', 'u', 'a', 'code', 'pre',
        'ul', 'ol', 'li', 'br', 'blockquote',
    ];

    /**
     * Tags dropped together with everything inside them, instead of being
     * unwrapped like every other disallowed tag.
     *
     * Unwrapping keeps a disallowed element's children and re-checks them.
     * That is right for containers of prose (`<div>`, `<section>`, …) but
     * wrong for these: their content is never body text in the first place.
     *
     *  - `script` / `style`: libxml's HTML parser stores their content in an
     *    XML_CDATA_SECTION_NODE, and `DOMDocument::saveHTML()` writes a CDATA
     *    section back out *verbatim* — no entity escaping. Promoting that
     *    child re-injected live markup into the output
     *    (`<style><img src=x onerror=…></style>` came back as a live `<img>`).
     *    Converting it to a text node would be safe, but would then paint the
     *    raw CSS/JS source onto the page as visible prose, which is not what
     *    anyone who typed a `<style>` block meant. Dropping is both safe and
     *    unsurprising.
     *  - `template`: its content is inert in a real browser and is a standard
     *    mXSS carrier; promoting it makes inert markup live.
     *  - `noscript` / `noembed` / `noframes` / `iframe` / `object` / `embed` /
     *    `applet` / `frame` / `frameset`: fallback or embedded content that
     *    browsers parse under rules libxml does not share. Any parser
     *    disagreement here is an mXSS primitive, so we do not carry the
     *    content forward at all.
     *  - `title` / `head` / `base` / `meta` / `link`: document metadata, never
     *    body prose.
     */
    private const DROP_SUBTREE_TAGS = [
        'script', 'style', 'template',
        'noscript', 'noembed', 'noframes',
        'iframe', 'object', 'embed', 'applet', 'frame', 'frameset',
        'title', 'head', 'base', 'meta', 'link',
    ];

    /** Attributes allowed per tag. '*' means any tag. */
    private const ALLOWED_ATTRS = [
        '*'  => [],
        'a'  => ['href', 'title', 'target'],
    ];

    /**
     * Extended allow-list, a strict superset of `ALLOWED_TAGS`. Adds
     * headings, horizontal rules, images, tables and inline `del/s`
     * marks on top of the comment/task baseline. Used by `cleanRich()`
     * for Knowledge-base Markdown output and for task descriptions
     * (`TasksHandler::sanitizeDescription()`, `TaskController::update()`).
     *
     * `div` carries the same zero-attribute treatment and the same reason
     * it is on `ALLOWED_TAGS`: Quill 2 renders a code block as
     * `<div class="ql-code-block-container">` wrapping one
     * `<div class="ql-code-block">` per line, and task descriptions are
     * authored in that same Quill editor. Every tag in `ALLOWED_TAGS` must
     * stay present here too — the two lists are documented as nested
     * (everything `clean()` permits, `cleanRich()` also permits), and
     * `tests/unit/test_html_sanitizer.php` asserts that nesting.
     */
    private const ALLOWED_TAGS_RICH = [
        'p', 'div', 'strong', 'em', 'u', 's', 'del', 'a', 'code', 'pre',
        'ul', 'ol', 'li', 'br', 'blockquote', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    private const ALLOWED_ATTRS_RICH = [
        '*'   => [],
        'a'   => ['href', 'title', 'target'],
        'img' => ['src', 'alt', 'title'],
        'th'  => ['align', 'colspan', 'rowspan'],
        'td'  => ['align', 'colspan', 'rowspan'],
    ];

    /**
     * `ALLOWED_ATTRS_RICH` plus `id` on headings — for `Markdown::renderRich()`
     * and nothing else. Reach it through `cleanRichWithAnchors()`.
     *
     * Who may use heading anchors, and why the others may not:
     *
     *  - **The wiki may.** `Markdown::renderRich()` renders with Parsedown in
     *    `setSafeMode(true)` + `setMarkupEscaped(true)`, so no author-supplied
     *    raw HTML reaches this sanitiser at all; every `<h1>`–`<h6>` in that
     *    HTML was emitted by Parsedown, and every `id` on one was generated by
     *    `Markdown::addHeadingAnchors()` from a slugified, per-document deduped
     *    heading text. The value is ours, not the author's, and wiki articles
     *    need it for their Table-of-Contents fragment links.
     *
     *  - **Task descriptions may not.** They arrive straight from the Quill
     *    editor and from `PATCH /api/v1/tasks/{id}`, with no Parsedown in front,
     *    so an author picks the `id` byte-for-byte. `id` is not an XSS vector by
     *    itself, but it is a *clobbering* one: `views/layouts/main.php` emits
     *    page content before `#modal-root`, `#toast-root`, `#lightbox-root` and
     *    `#i18n-js`, so a planted duplicate wins `getElementById`. Planting
     *    `id="i18n-js"` with a JSON body hands the attacker every `t('js.*')`
     *    string on the page; planting `id="task-description-hidden"` makes
     *    `hidden.value` `undefined`, seeds Quill empty and lets the next save
     *    wipe the real description — a permanent edit trap, and one that works
     *    with CSP fully enabled.
     */
    private const ALLOWED_ATTRS_RICH_ANCHORS = [
        '*'   => [],
        'a'   => ['href', 'title', 'target'],
        'img' => ['src', 'alt', 'title'],
        'th'  => ['align', 'colspan', 'rowspan'],
        'td'  => ['align', 'colspan', 'rowspan'],
        'h1'  => ['id'],
        'h2'  => ['id'],
        'h3'  => ['id'],
        'h4'  => ['id'],
        'h5'  => ['id'],
        'h6'  => ['id'],
    ];

    public static function clean(string $html): string
    {
        return self::cleanWith($html, self::ALLOWED_TAGS, self::ALLOWED_ATTRS);
    }

    /**
     * Sanitise rich HTML — same XSS defences as `clean()` but with a wider
     * tag whitelist (headings, tables, images, hr). Used for Knowledge-base
     * Markdown output and for task descriptions, both of which need to
     * carry structure `clean()` would strip. Other Quill/comment surfaces
     * (project descriptions, comments, polls, forms) should keep using
     * `clean()` so they don't accidentally accept e.g. raw <table> — project
     * descriptions in particular stay narrow on purpose even when copied
     * from a task description that was allowed to contain one (see
     * `TaskController::promoteToProject()` /
     * `TasksHandler::promoteToProject()`).
     *
     * Emits **no `id`** on any tag. Heading anchors live in
     * `cleanRichWithAnchors()`, which only `Markdown::renderRich()` uses.
     */
    public static function cleanRich(string $html): string
    {
        return self::cleanWith($html, self::ALLOWED_TAGS_RICH, self::ALLOWED_ATTRS_RICH);
    }

    /**
     * `cleanRich()` plus `id` on headings. **Only `Markdown::renderRich()` may
     * call this** — see `ALLOWED_ATTRS_RICH_ANCHORS` for the full argument, in
     * short: there the ids are generated PHP-side from slugified heading text
     * and no raw author HTML survives Parsedown's safe mode, whereas a task
     * description picks its own `id` values and would clobber the app shell's
     * `#i18n-js` / `#task-description-hidden` / `#modal-root` elements.
     */
    public static function cleanRichWithAnchors(string $html): string
    {
        return self::cleanWith($html, self::ALLOWED_TAGS_RICH, self::ALLOWED_ATTRS_RICH_ANCHORS);
    }

    /**
     * @param list<string> $tags
     * @param array<string,list<string>> $attrs
     */
    private static function cleanWith(string $html, array $tags, array $attrs): string
    {
        if ($html === '') {
            return '';
        }
        return self::run($html, $tags, $attrs);
    }

    /**
     * @param list<string> $tags
     * @param array<string,list<string>> $attrs
     */
    private static function run(string $html, array $tags, array $attrs): string
    {

        // ext-dom is hard-required at boot (system/bootstrap.php) — no fallback.
        $doc = new \DOMDocument();
        // Suppress warnings from malformed HTML; wrap in UTF-8 body
        @$doc->loadHTML(
            '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
            \LIBXML_NOWARNING | \LIBXML_NOERROR
        );

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        self::sanitiseNode($body, $tags, $attrs);

        // Serialise inner HTML of body
        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * @param list<string> $tags
     * @param array<string,list<string>> $attrs
     */
    private static function sanitiseNode(\DOMNode $node, array $tags, array $attrs): void
    {
        // Walk with a live cursor instead of a snapshot + deferred removal.
        // Unwrapping a disallowed element promotes its children up into this
        // level, and those children have never been checked. A snapshot walk
        // never revisits them, so `<div><img src=x onerror=...></div>` came
        // back out as a live `<img onerror>`: one wrapper in a tag that is not
        // on the allow list was enough to smuggle any payload through. The
        // cursor resumes at the first promoted node so the newly exposed
        // subtree is sanitised too. Each unwrap removes one element, so this
        // terminates.
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;

            // Comment nodes are dropped entirely (content included), and so are
            // processing instructions (`<?php …`, `<?xml-stylesheet …`).
            // libxml keeps a PI as an XML_PI_NODE and saveHTML() writes it back
            // verbatim, so a `<?php …` PI pasted between two paragraphs used
            // to survive `clean()` byte-for-byte. A browser cannot be made to
            // execute it (libxml's htmlParsePI and the HTML5 bogus-comment rule
            // both stop at the first raw `>`, so the bytes always land inside a
            // comment), but it is the third member of the node-type class the
            // CDATA guard below was written to catch, it silently defeats the
            // comment-stripping policy, and it mutates stored content.
            if ($child->nodeType === \XML_COMMENT_NODE || $child->nodeType === \XML_PI_NODE) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            // CDATA sections serialise *verbatim* through saveHTML(), so a
            // stray one is raw HTML injection. DROP_SUBTREE_TAGS below already
            // removes the only two elements libxml ever builds one for
            // (`script`/`style`), so this branch should be unreachable — it is
            // here as a second line of defence, in case a future libxml or a
            // future allow-list edit surfaces a CDATA node somewhere else.
            // Replacing it with a real text node makes it get escaped.
            if ($child->nodeType === \XML_CDATA_SECTION_NODE) {
                $text = $node->ownerDocument?->createTextNode((string)$child->nodeValue);
                if ($text !== null) {
                    $node->replaceChild($text, $child);
                } else {
                    $node->removeChild($child);
                }
                $child = $next;
                continue;
            }

            // Text nodes are kept as-is.
            if ($child->nodeType !== \XML_ELEMENT_NODE) {
                $child = $next;
                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_SUBTREE_TAGS, true)) {
                // Drop element *and* content — see DROP_SUBTREE_TAGS.
                $node->removeChild($child);
                $child = $next;
                continue;
            }
            if (!in_array($tag, $tags, true)) {
                // Unwrap: keep the contents, drop the element itself.
                $promoted = $child->firstChild;
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $child = $promoted ?? $next;
                continue;
            }

            self::cleanAttributes($child, $tag, $attrs);
            self::sanitiseNode($child, $tags, $attrs);
            $child = $next;
        }
    }

    /**
     * @param array<string,list<string>> $attrs
     */
    private static function cleanAttributes(\DOMElement $el, string $tag, array $attrs): void
    {
        $allowed = array_merge(
            $attrs['*'] ?? [],
            $attrs[$tag] ?? []
        );

        $removeAttrs = [];
        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->name);
            if (!in_array($name, $allowed, true)) {
                $removeAttrs[] = $name;
                continue;
            }
            // Sanitise href: http/https/mailto/in-page fragment, plus a
            // root-relative path (a single leading "/") so internal links
            // (e.g. /projects/1) survive. Fragment-only links (#section-name)
            // are the backbone of wiki Tables-of-Contents — they can't
            // navigate off-site so they're safe.
            //
            // The root-relative branch is deliberately narrow: it matches
            // "/" NOT followed by another "/" or by "\". A second slash
            // ("//host/path") is a protocol-relative *absolute* URL to
            // another origin — that must stay blocked. A backslash right
            // after the leading slash ("/\host") is the same attack in
            // disguise: browsers normalise "\" to "/" while parsing special
            // schemes, so "/\evil.example" is parsed identically to
            // "//evil.example" and would also jump origin. Encoded slashes
            // ("/%2Fevil.example", "/%5Cevil.example") are NOT decoded by the
            // WHATWG URL parser into an authority separator before host
            // resolution — for src as well as href — so those stay
            // same-origin paths and are allowed. Deliberately not extended to
            // "./", "../", or bare relative paths — this application never
            // emits those, so there's no reason to widen the allow-list.
            //
            // THE INVARIANT THIS BRANCH DEPENDS ON: the value that is
            // *serialised* must still begin with a single slash. That is not
            // automatic — libxml re-serialises attribute values on output and
            // does not round-trip every byte (see urlAllowed() below). Testing
            // the raw value while serving a different one is exactly how
            // "/<0x0B>/evil.example" once became "//evil.example". urlAllowed()
            // is what makes the checked string and the served string the same
            // string; do not inline a raw preg_match() here again.
            if ($name === 'href') {
                if (!self::urlAllowed($attr, '/^(https?:\/\/|mailto:|#|\/(?!\/|\\\\))/i')) {
                    $removeAttrs[] = $name;
                }
            }
            // Sanitise img src: http/https/data:image, plus the same
            // root-relative path form as href — every uploaded image is
            // served from a root-relative path (/uploads/...).
            if ($name === 'src') {
                if (!self::urlAllowed($attr, '/^(https?:\/\/|data:image\/|\/(?!\/|\\\\))/i')) {
                    $removeAttrs[] = $name;
                }
            }
            // Block event handler attributes
            if (str_starts_with($name, 'on')) {
                $removeAttrs[] = $name;
            }
        }

        foreach ($removeAttrs as $name) {
            $el->removeAttribute($name);
        }

        // Force target="_blank" to have rel="noopener noreferrer"
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Decide whether an href/src value is on the allow-list, and guarantee
     * that the string approved here is the string the browser will receive.
     *
     * libxml re-serialises attribute values when the document is written out,
     * and it does NOT round-trip every byte:
     *
     *   0x01–0x08, 0x0B, 0x0C, 0x0E–0x1F  silently DROPPED
     *   0x00                              TRUNCATES the value
     *   0x09, 0x0A, 0x0D, 0x20, 0x7F      kept, percent-encoded as %XX
     *
     * So matching against the raw value tests a different string from the one
     * that ships. "/\x0B/evil.example" passes a naive "slash not followed by
     * slash" test and then serialises as "//evil.example" — a protocol-relative
     * URL to an attacker's origin. Two steps close that gap:
     *
     *  1. MATCH against a probe with every C0/space/DEL byte removed. This can
     *     only ever narrow the accepted set: every prefix the stripping could
     *     "repair" a value into (https://, mailto:, #, /path) is already on the
     *     allow-list, while obfuscated forms ("java\tscript:", "/\x0B/host")
     *     collapse onto their real meaning and get rejected.
     *
     *  2. WRITE BACK a value with only the non-round-tripping bytes removed —
     *     the ones libxml drops or truncates on. Whitespace (tab/LF/CR/space)
     *     and DEL are deliberately KEPT, because libxml preserves them
     *     losslessly as %09/%0A/%0D/%20/%7F: a space inside "/uploads/a b.png"
     *     is meaningful path data and percent-encoding is its correct
     *     serialisation, so deleting it would silently break real links.
     *     Percent-encoding can never turn into a slash, so keeping those bytes
     *     cannot resurrect an authority separator.
     *
     * Net effect: what is stored and served is byte-identical to what was
     * checked, modulo visible, reversible percent-encoding.
     */
    private static function urlAllowed(\DOMAttr $attr, string $pattern): bool
    {
        $val = trim($attr->value);

        // Bytes libxml does not round-trip: NUL truncates, the rest vanish.
        $stored = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $val);
        // Probe: additionally drop tab/LF/CR/space/DEL for matching only.
        $probe  = preg_replace('/[\x00-\x20\x7F]+/', '', $val);

        if (!preg_match($pattern, $probe)) {
            return false;
        }
        if ($stored !== $attr->value) {
            $attr->value = $stored;
        }
        return true;
    }
}
