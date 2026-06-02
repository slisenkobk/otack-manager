<?php
declare(strict_types=1);
namespace App\Service;

/**
 * Renders {placeholder} strings used for the auto-create-task title on form
 * submission. Pure value-in / value-out so it can be unit-tested without DB.
 *
 * Supported placeholders:
 *   {formName} / {form.title}   — form title
 *   {form.id}                   — form numeric id
 *   {submission.id}             — submission numeric id
 *   {<field_key>}               — submitted value for that field (array → comma-join)
 *
 * Unknown placeholders are left intact so authors notice typos in the title
 * instead of silently producing "  - new project ".
 */
final class FormTemplate
{
    public static function renderTaskTitle(
        ?string $template,
        array $form,
        array $submission,
        array $data,
        int $maxLen = 200
    ): string {
        $tpl = trim((string)$template);
        if ($tpl === '') {
            $fallback = ((string)($form['title'] ?? 'Form')) . ' #' . (int)($submission['id'] ?? 0);
            return mb_substr($fallback, 0, $maxLen);
        }

        $reserved = [
            'formName'      => (string)($form['title'] ?? ''),
            'form.title'    => (string)($form['title'] ?? ''),
            'form.id'       => (string)($form['id'] ?? ''),
            'submission.id' => (string)($submission['id'] ?? ''),
        ];

        $out = preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($reserved, $data) {
            $key = $m[1];
            if (array_key_exists($key, $reserved)) return $reserved[$key];
            if (array_key_exists($key, $data)) {
                $v = $data[$key];
                if (is_array($v)) return implode(', ', array_map(fn($x) => (string)$x, $v));
                return (string)$v;
            }
            return $m[0];
        }, $tpl);

        $out = trim((string)$out);
        if ($out === '') {
            $out = ((string)($form['title'] ?? 'Form')) . ' #' . (int)($submission['id'] ?? 0);
        }
        return mb_substr($out, 0, $maxLen);
    }

    /**
     * Build the HTML body shown on a task created from a form submission —
     * "From form X / submission #N" header plus one paragraph per non-empty
     * field. Shared by the public auto-task path and the admin promote-to-task
     * action so the two stay byte-for-byte identical.
     */
    public static function renderTaskBody(array $form, array $fields, array $data, int $submissionId): string {
        $lines = ['<p><em>From form: ' . htmlspecialchars((string)$form['title'], ENT_QUOTES, 'UTF-8')
                . ' / submission #' . $submissionId . '</em></p>'];
        foreach ($fields as $f) {
            $v = $data[$f['key']] ?? '';
            if (is_array($v)) $v = implode(', ', $v);
            $v = trim((string)$v);
            if ($v === '') continue;
            $lines[] = '<p><strong>' . htmlspecialchars((string)$f['label'], ENT_QUOTES, 'UTF-8') . ':</strong><br>'
                     . nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return implode('', $lines);
    }
}
