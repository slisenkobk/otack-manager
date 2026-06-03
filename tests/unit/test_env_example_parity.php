<?php
declare(strict_types=1);

/**
 * Convention test (O-3): every `App::env('FOO', ...)` lookup in `system/`
 * must have a documenting row in `.env.example` so operators discover
 * tunables without reading PHP. The allowlist is intentionally empty —
 * add only with justification.
 */

it('every App::env() key has a documentation row in .env.example', function () {
    $root = dirname(__DIR__, 2);
    $envExamplePath = $root . '/.env.example';
    $envExample = (string)file_get_contents($envExamplePath);

    $code = '';
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/system'));
    foreach ($rii as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $code .= file_get_contents($f->getPathname());
        }
    }
    preg_match_all("/App::env\\(\\s*['\"]([A-Z_][A-Z0-9_]*)['\"]/", $code, $m);
    $keys = array_values(array_unique($m[1]));

    // Allowlist — keep empty unless a key has a strong reason to be undocumented.
    $allowlist = [];

    $missing = [];
    foreach ($keys as $k) {
        if (in_array($k, $allowlist, true)) continue;
        if (!preg_match('/^' . preg_quote($k, '/') . '=/m', $envExample)) {
            $missing[] = $k;
        }
    }
    assert_true(empty($missing), '.env.example missing rows for: ' . implode(', ', $missing));
});
