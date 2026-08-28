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
        // Heading anchors emitted by Markdown::addHeadingAnchors() so
        // wiki articles can link to their own sections. id values are
        // pre-slugified PHP-side so injection through this attr would
        // need to pre-poison the rendered HTML before it reaches us.
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
     */
    public static function cleanRich(string $html): string
    {
        return self::cleanWith($html, self::ALLOWED_TAGS_RICH, self::ALLOWED_ATTRS_RICH);
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

            // Comment nodes are dropped entirely (content included).
            if ($child->nodeType === \XML_COMMENT_NODE) {
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
            // Sanitise href: only http/https/mailto/in-page fragment.
            // Fragment-only links (#section-name) are the backbone of
            // wiki Tables-of-Contents — they can't navigate off-site so
            // they're safe.
            if ($name === 'href') {
                $val = trim($attr->value);
                if (!preg_match('/^(https?:\/\/|mailto:|#)/i', $val)) {
                    $removeAttrs[] = $name;
                }
            }
            // Sanitise img src: only http/https/data:image allowed
            if ($name === 'src') {
                $val = trim($attr->value);
                if (!preg_match('/^(https?:\/\/|data:image\/)/i', $val)) {
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
}
