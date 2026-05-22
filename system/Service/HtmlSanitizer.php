<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Minimal HTML allow-list sanitiser.
 * Strips any tag not on the allow list and removes dangerous attributes
 * (event handlers, javascript: hrefs, data: urls).
 *
 * Used for Quill WYSIWYG output before persisting descriptions.
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

    public static function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Use DOMDocument if available; fall back to strip_tags
        if (!extension_loaded('dom')) {
            return strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');
        }

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

        self::sanitiseNode($body);

        // Serialise inner HTML of body
        $inner = '';
        foreach ($body->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return trim($inner);
    }

    private static function sanitiseNode(\DOMNode $node): void
    {
        $remove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === \XML_ELEMENT_NODE) {
                /** @var \DOMElement $child */
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Replace with text content (unwrap)
                    $remove[] = ['node' => $child, 'unwrap' => true];
                } else {
                    // Strip disallowed attributes
                    self::cleanAttributes($child, $tag);
                    // Recurse
                    self::sanitiseNode($child);
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

    private static function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = array_merge(
            self::ALLOWED_ATTRS['*'] ?? [],
            self::ALLOWED_ATTRS[$tag] ?? []
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
