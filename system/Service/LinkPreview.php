<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Wraps plain <a href="http..."> elements rendered from markdown/Quill into
 * a styled "link card" with a truncated URL preview and an Open button.
 * Skips anchors that are already cards, contain images, or live inside <code>/<pre>.
 */
final class LinkPreview
{
    public static function enhance(string $html): string
    {
        if ($html === '') return $html;

        // Stash <code>/<pre> / existing <a> blocks so their inner text isn't touched.
        $stash = [];
        $protect = function (string $pattern, string $source) use (&$stash) {
            return preg_replace_callback($pattern, function ($m) use (&$stash) {
                $key = "\0STASH_" . count($stash) . "\0";
                $stash[] = $m[0];
                return $key;
            }, $source);
        };
        $html = $protect('#<(code|pre)\b[^>]*>.*?</\1>#is', $html);
        $html = $protect('#<a\b[^>]*>.*?</a>#is', $html);

        // Auto-link bare URLs anywhere outside tags (text nodes only).
        $html = preg_replace_callback(
            '#(?<=^|[\s>\(\[])(https?://[^\s<>"\)\]]+)#i',
            fn ($m) => self::buildCardHtml($m[1], ''),
            $html
        );

        // Restore stashed segments, upgrading plain <a href="http..."> to cards.
        foreach ($stash as $i => $original) {
            $upgraded = preg_replace_callback(
                '#<a\b([^>]*)\bhref="(https?://[^"]+)"([^>]*)>(.*?)</a>#is',
                function ($m) {
                    $beforeAttr = $m[1] . ' ' . $m[3];
                    if (preg_match('/class="[^"]*\blink-card\b/i', $beforeAttr)) return $m[0];
                    $inner = trim($m[4]);
                    if (preg_match('/<(img|video|svg|picture|iframe)\b/i', $inner)) return $m[0];
                    return self::buildCardHtml($m[2], $inner);
                },
                $original
            );
            $html = str_replace("\0STASH_{$i}\0", $upgraded, $html);
        }
        return $html;
    }

    private static function buildCardHtml(string $url, string $inner): string
    {
        $pretty = self::prettyUrl($url);
        $label  = trim($inner) !== '' ? trim($inner) : $pretty;
        $isPretty = ($label === $pretty || $label === $url);
        $main = $isPretty
            ? '<span class="link-card__url">' . self::escape($pretty) . '</span>'
            : '<span class="link-card__label">' . self::escape($label) . '</span>'
              . '<span class="link-card__url">' . self::escape($pretty) . '</span>';
        return '<a href="' . self::escape($url) . '" class="link-card" target="_blank" rel="noopener noreferrer" spellcheck="false">'
             . '<span class="link-card__icon"><i class="fa-solid fa-link"></i></span>'
             . '<span class="link-card__body">' . $main . '</span>'
             . '<span class="link-card__cta">Open <i class="fa-solid fa-arrow-up-right-from-square"></i></span>'
             . '</a>';
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

    private static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
