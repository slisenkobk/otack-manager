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

    /** Attributes allowed per tag. '*' means any tag. */
    private const ALLOWED_ATTRS = [
        '*'  => [],
        'a'  => ['href', 'title', 'target'],
    ];

    /**
     * Extended allow-list for Knowledge-base Markdown output. Adds
     * headings, horizontal rules, images, tables and inline `del/s`
     * marks on top of the comment baseline. Used by `cleanRich()`.
     */
    private const ALLOWED_TAGS_RICH = [
        'p', 'strong', 'em', 'u', 's', 'del', 'a', 'code', 'pre',
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
     * Sanitise rich Markdown output for the Knowledge base — same XSS
     * defences as `clean()` but with a wider tag whitelist (headings,
     * tables, images, hr). Comment / Quill surfaces should keep using
     * `clean()` so they don't accidentally accept e.g. raw <table>.
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

            // Text nodes are kept as-is.
            if ($child->nodeType !== \XML_ELEMENT_NODE) {
                $child = $next;
                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->tagName);
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
