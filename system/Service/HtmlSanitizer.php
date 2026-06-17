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
    /** Tags whose textContent we keep but whose element we strip. */
    private const ALLOWED_TAGS = [
        'p', 'strong', 'em', 'u', 'a', 'code', 'pre',
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
        $remove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === \XML_ELEMENT_NODE) {
                /** @var \DOMElement $child */
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $tags, true)) {
                    // Replace with text content (unwrap)
                    $remove[] = ['node' => $child, 'unwrap' => true];
                } else {
                    // Strip disallowed attributes
                    self::cleanAttributes($child, $tag, $attrs);
                    // Recurse
                    self::sanitiseNode($child, $tags, $attrs);
                }
            }
            // Text nodes and comment nodes are kept as-is (comments filtered below)
            if ($child->nodeType === \XML_COMMENT_NODE) {
                $remove[] = ['node' => $child, 'unwrap' => false];
            }
        }

        foreach ($remove as $item) {
            $n = $item['node'];
            if ($item['unwrap']) {
                // Move child text nodes up
                while ($n->firstChild) {
                    $node->insertBefore($n->firstChild, $n);
                }
            }
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
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
            // Sanitise href: only http/https/mailto allowed
            if ($name === 'href') {
                $val = trim($attr->value);
                if (!preg_match('/^(https?:\/\/|mailto:)/i', $val)) {
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
