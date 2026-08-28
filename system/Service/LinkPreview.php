<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Wraps plain <a href="http..."> elements rendered from markdown/Quill into
 * a styled "link card" with a truncated URL preview and an Open button, and
 * auto-links bare `http(s)://…` URLs found in ordinary prose.
 * Skips anchors that are already cards, contain images, or live inside <code>/<pre>.
 *
 * ## Why this walks the DOM instead of regexing the HTML string
 *
 * This runs *after* `HtmlSanitizer`, on its way to the page — it is the last
 * thing that touches the markup, so anything it emits is what the browser
 * parses. An earlier revision matched bare URLs against the raw HTML string
 * with a `preg_replace_callback`. That regex could not tell prose from an
 * attribute value, so a URL parked inside an attribute the allow-list permits
 * (`img[alt]`, `img[title]`, `h1`–`h6`[id]`, `th`/`td`[align]`) was rewritten
 * into a raw `<a href="…" …>` *inside the quoted value*. The browser then
 * re-tokenised the injected markup and hung the URL's `/`-separated segments
 * on the host element as live attributes — including `on*` handlers, i.e.
 * stored XSS that the sanitiser had already cleared. Blacklisting the
 * currently-reachable attributes would only hold until the next allow-list
 * widening, so the fix is structural: parse, mutate text nodes only, build
 * cards with the DOM API (never by concatenating markup), re-serialise.
 * There is no code path here that can write into an attribute value.
 *
 * That does not make the allow-list the last gate: `buildCardNode()` below
 * adds `<span>`/`<i>` wrappers and `class`/`rel`/`spellcheck` attributes that
 * are not on `HtmlSanitizer::ALLOWED_TAGS_RICH`/`ALLOWED_ATTRS_RICH`, so
 * markup outside the allow-list does reach the browser. What `enhance()`
 * guarantees instead: it never writes caller-controlled bytes into an
 * attribute value. Everything it adds is a fixed, server-controlled
 * literal; the only caller-derived value it emits is an `href`, and that
 * value's `https?://` prefix is pinned by the matcher that found it.
 */
final class LinkPreview
{
    /** Elements whose text is never auto-linked (source code stays verbatim). */
    private const SKIP_SUBTREE_TAGS = ['code', 'pre'];

    /** An existing <a> wrapping any of these is left exactly as authored. */
    private const RICH_CHILD_TAGS = ['img', 'video', 'svg', 'picture', 'iframe'];

    public static function enhance(string $html): string
    {
        if ($html === '') return $html;
        // Nothing to enhance without a scheme — return the input byte-for-byte
        // rather than paying for (and risking a normalisation from) a parse.
        if (stripos($html, 'http') === false) return $html;

        // ext-dom is hard-required at boot (system/bootstrap.php) — no fallback.
        $doc = new \DOMDocument();
        @$doc->loadHTML(
            '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>',
            \LIBXML_NOWARNING | \LIBXML_NOERROR
        );
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return $html;

        self::walk($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * Depth-first walk. Only text nodes are rewritten; elements are either
     * descended into, skipped wholesale, or (for `<a>`) upgraded in place.
     */
    private static function walk(\DOMNode $node): void
    {
        $child = $node->firstChild;
        while ($child !== null) {
            // Captured before any mutation: replacing $child must not lose our
            // place, and newly created cards must not be re-visited.
            $next = $child->nextSibling;

            if ($child->nodeType === \XML_ELEMENT_NODE) {
                /** @var \DOMElement $child */
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::SKIP_SUBTREE_TAGS, true)) {
                    $child = $next;
                    continue;
                }
                if ($tag === 'a') {
                    // Never descend into an anchor: its text is the link label,
                    // not prose, and nested anchors are not valid HTML.
                    self::upgradeAnchor($child);
                    $child = $next;
                    continue;
                }
                self::walk($child);
            } elseif ($child->nodeType === \XML_TEXT_NODE) {
                /** @var \DOMText $child */
                self::autoLinkText($child);
            }

            $child = $next;
        }
    }

    /** Turn a plain http(s) anchor into a card, in place. */
    private static function upgradeAnchor(\DOMElement $a): void
    {
        $href = trim($a->getAttribute('href'));
        if (!preg_match('#^https?://#i', $href)) return;
        // Already a card (e.g. enhance() run twice) — leave it alone.
        if (preg_match('/(?:^|\s)link-card(?:\s|$)/i', $a->getAttribute('class'))) return;
        // Image/media links keep their own presentation.
        foreach (self::RICH_CHILD_TAGS as $tag) {
            if ($a->getElementsByTagName($tag)->length > 0) return;
        }
        $doc = $a->ownerDocument;
        if ($doc === null) return;
        $card = self::buildCardNode($doc, $href, trim($a->textContent));
        $a->parentNode?->replaceChild($card, $a);
    }

    /**
     * Replace bare URLs inside one text node with card elements.
     *
     * The `^` in the lookbehind is the start of *this text node*, which is
     * exactly the "URL sits right after a tag" case the old string-level regex
     * spelled as `>`.
     */
    private static function autoLinkText(\DOMText $text): void
    {
        $value = $text->nodeValue ?? '';
        if (stripos($value, 'http') === false) return;

        $parts = preg_split(
            '#(?<=^|[\s\(\[])(https?://[^\s<>"\)\]]+)#i',
            $value,
            -1,
            \PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false || count($parts) < 2) return;

        $doc    = $text->ownerDocument;
        $parent = $text->parentNode;
        if ($doc === null || $parent === null) return;

        $frag = $doc->createDocumentFragment();
        foreach ($parts as $i => $part) {
            if ($part === '') continue;
            // Odd indices are the captured URLs (PREG_SPLIT_DELIM_CAPTURE).
            $frag->appendChild(
                $i % 2 === 1
                    ? self::buildCardNode($doc, $part, '')
                    : $doc->createTextNode($part)
            );
        }
        $parent->replaceChild($frag, $text);
    }

    /**
     * Build the card as a DOM subtree. Every attribute goes through
     * `setAttribute()` and every label through `createTextNode()`, so the
     * serialiser does the escaping and no caller-controlled byte can end a
     * quoted value or open a tag.
     */
    private static function buildCardNode(\DOMDocument $doc, string $url, string $inner): \DOMElement
    {
        $pretty   = self::prettyUrl($url);
        $label    = trim($inner) !== '' ? trim($inner) : $pretty;
        $isPretty = ($label === $pretty || $label === $url);

        $a = $doc->createElement('a');
        $a->setAttribute('href', $url);
        $a->setAttribute('class', 'link-card');
        $a->setAttribute('target', '_blank');
        $a->setAttribute('rel', 'noopener noreferrer');
        $a->setAttribute('spellcheck', 'false');

        $icon = self::span($doc, 'link-card__icon');
        $icon->appendChild(self::icon($doc, 'fa-solid fa-link'));
        $a->appendChild($icon);

        $bodyEl = self::span($doc, 'link-card__body');
        if (!$isPretty) {
            $labelEl = self::span($doc, 'link-card__label');
            $labelEl->appendChild($doc->createTextNode($label));
            $bodyEl->appendChild($labelEl);
        }
        $urlEl = self::span($doc, 'link-card__url');
        $urlEl->appendChild($doc->createTextNode($pretty));
        $bodyEl->appendChild($urlEl);
        $a->appendChild($bodyEl);

        $cta = self::span($doc, 'link-card__cta');
        $cta->appendChild($doc->createTextNode('Open '));
        $cta->appendChild(self::icon($doc, 'fa-solid fa-arrow-up-right-from-square'));
        $a->appendChild($cta);

        return $a;
    }

    private static function span(\DOMDocument $doc, string $class): \DOMElement
    {
        $el = $doc->createElement('span');
        $el->setAttribute('class', $class);
        return $el;
    }

    private static function icon(\DOMDocument $doc, string $class): \DOMElement
    {
        $el = $doc->createElement('i');
        $el->setAttribute('class', $class);
        return $el;
    }

    private static function prettyUrl(string $url): string
    {
        $stripped = preg_replace('#^https?://#i', '', $url);
        $stripped = rtrim($stripped, '/');
        $max = 56;
        if (mb_strlen($stripped) <= $max) return $stripped;
        $headLen = (int) floor(($max - 3) * 0.7);
        $tailLen = ($max - 3) - $headLen;
        return mb_substr($stripped, 0, $headLen) . '…' . mb_substr($stripped, -$tailLen);
    }
}
