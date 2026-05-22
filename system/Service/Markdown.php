<?php
declare(strict_types=1);
namespace App\Service;

final class Markdown
{
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
