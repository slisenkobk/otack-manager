<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Markdown rendering. Two surfaces:
 *
 *  - `render()` — hand-rolled minimal parser used by comments. Inputs
 *    are short, the grammar is intentionally limited (bold / inline
 *    code / bullet+ordered lists / links), and we want zero extra
 *    surface area to attack. Pre-escapes the whole string first.
 *
 *  - `renderRich()` — full CommonMark-ish rendering for Knowledge-base
 *    pages via vendored Parsedown 1.7.4 (system/vendor/Parsedown.php).
 *    Safe-mode + setMarkupEscaped(true) on Parsedown, then a defence-
 *    in-depth pass through HtmlSanitizer. Use this for any longer-form
 *    Markdown surface where headings / blockquotes / fenced code /
 *    tables matter.
 */
final class Markdown
{
    private static ?\Parsedown $rich = null;

    /**
     * Full-fat Markdown for the Knowledge base. Safe to render
     * untrusted user-supplied Markdown: Parsedown safeMode strips raw
     * HTML and blocks `javascript:` URLs, and HtmlSanitizer runs the
     * resulting HTML through the same allow-list we use for Quill
     * output everywhere else.
     */
    public static function renderRich(string $md): string
    {
        if (trim($md) === '') return '';
        if (self::$rich === null) {
            require_once dirname(__DIR__) . '/vendor/Parsedown.php';
            $p = new \Parsedown();
            $p->setSafeMode(true);
            // Belt-and-braces — even in safe mode Parsedown may emit
            // inline HTML in some edge cases. setMarkupEscaped() forces
            // it to escape every `<`/`>` that wasn't produced by
            // Parsedown's own grammar.
            $p->setMarkupEscaped(true);
            self::$rich = $p;
        }
        // Parsedown 1.7.4 has implicit-nullable signatures that trigger
        // E_DEPRECATED warnings on PHP 8.3+. They're harmless (output
        // is correct), and the upstream class is in long-running 2.x
        // refactor — silence at the call site rather than patch the
        // vendored file.
        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        try {
            $html = self::$rich->text($md);
        } finally {
            error_reporting($prev);
        }
        $html = self::addHeadingAnchors($html);
        // cleanRichWithAnchors(), not cleanRich(): the heading `id`s were just
        // generated above from slugified heading text, and wiki Tables-of-
        // Contents link to them. This is the *only* caller allowed that attr —
        // see HtmlSanitizer::ALLOWED_ATTRS_RICH_ANCHORS.
        return HtmlSanitizer::cleanRichWithAnchors($html);
    }

    /**
     * Post-process Parsedown output to give every <h1>..<h6> a stable id
     * derived from its text content — enables in-page anchor links and
     * Table-of-Contents pointers in wiki articles.
     *
     * Algorithm: slugify text content, dedupe with -2/-3 suffixes per
     * document so multiple headings with the same text still get unique
     * targets. Existing `id="…"` attributes are left alone — authors who
     * hand-craft anchors win.
     */
    private static function addHeadingAnchors(string $html): string
    {
        $seen = [];
        return preg_replace_callback(
            '#<(h[1-6])(\s[^>]*)?>(.*?)</\1>#is',
            static function (array $m) use (&$seen): string {
                $tag    = $m[1];
                $attrs  = $m[2] ?? '';
                $inner  = $m[3];
                if (stripos($attrs, ' id=') !== false) {
                    return $m[0];
                }
                $text = trim(strip_tags($inner));
                $slug = self::slugifyHeading($text);
                if ($slug === '') return $m[0];
                $base = $slug;
                $n = 2;
                while (isset($seen[$slug])) { $slug = $base . '-' . $n; $n++; }
                $seen[$slug] = true;
                return '<' . $tag . $attrs . ' id="' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</' . $tag . '>';
            },
            $html
        ) ?? $html;
    }

    private static function slugifyHeading(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        // Drop everything that isn't a Unicode letter, digit or space/dash.
        $text = preg_replace('/[^\p{L}\p{N}\s\-]+/u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', '-', trim($text)) ?? '';
        $text = preg_replace('/-+/u', '-', $text) ?? '';
        return trim($text, '-');
    }

    public static function render(string $src): string
    {
        $src = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $blocks = preg_split('/\n\s*\n+/', trim($src));
        $out = '';
        foreach ($blocks as $block) {
            $block = trim($block, "\n");
            if ($block === '') continue;

            // Fenced code block
            if (preg_match('/^```/', $block)) {
                $lines = explode("\n", $block);
                array_shift($lines); // remove opening ```
                if (count($lines) > 0 && preg_match('/^```/', end($lines))) {
                    array_pop($lines); // remove closing ```
                }
                $out .= '<pre><code>' . implode("\n", $lines) . '</code></pre>';
                continue;
            }

            $lines = explode("\n", $block);

            // Unordered list: every line starts with "- "
            $allUL = count($lines) > 0
                && !array_filter($lines, fn($l) => !preg_match('/^- /', $l));
            // Ordered list: every line starts with a digit(s) + ". "
            $allOL = count($lines) > 0
                && !array_filter($lines, fn($l) => !preg_match('/^\d+\. /', $l));

            if ($allUL) {
                $out .= '<ul>';
                foreach ($lines as $l) {
                    $content = preg_replace('/^- /', '', $l, 1);
                    $out .= '<li>' . self::inline($content) . '</li>';
                }
                $out .= '</ul>';
                continue;
            }

            if ($allOL) {
                $out .= '<ol>';
                foreach ($lines as $l) {
                    $content = preg_replace('/^\d+\. /', '', $l, 1);
                    $out .= '<li>' . self::inline($content) . '</li>';
                }
                $out .= '</ol>';
                continue;
            }

            // Paragraph
            $out .= '<p>' . self::inline(implode("<br>\n", $lines)) . '</p>';
        }
        return $out;
    }

    private static function inline(string $s): string
    {
        // Bold: **text**
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);

        // Inline code: `text`
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);

        // Links: [text](url) — only allow https://, http://, mailto:
        $s = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function (array $m): string {
                $url = $m[2];
                if (preg_match('#^(https?://|mailto:)#i', $url)) {
                    return '<a href="' . $url . '" rel="noopener">' . $m[1] . '</a>';
                }
                // unsafe URL — render literal
                return $m[0];
            },
            $s
        );

        return $s;
    }
}
