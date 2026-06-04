<?php
declare(strict_types=1);

/**
 * Convention test (I-1): every key declared in `system/i18n/en.php` must
 * be referenced from PHP, JS, or test code via `t('key')` / `tn('key', ...)`
 * — or, in JS, via a bare `'key.subkey'` string literal that's later passed
 * to `t()` (e.g. lookup tables like `task-page.js` field→key maps).
 *
 * Dynamic prefixes (grep can't see runtime concatenation):
 *  - `activity.*`               — built as `'activity.' . $event` in views/partials/activity-row.php
 *  - `errors.*`                 — Validator builds error keys from field names
 *  - `status.project.*`         — `t('status.project.' . $code)` in views/dashboard + helpers
 *  - `status.user.*`            — `t('status.user.' . $status)` in views/users
 *  - `api_tokens.status_*`      — `'api_tokens.status_' . $status` in views/profile/tokens.php
 *  - `updates.kind.*`           — `t('updates.kind.' . $kind)` in views/admin/updates-tab.php
 *  - `updates.source.*`         — `t('updates.source.' . $source)` in views/admin/updates-tab.php
 *
 * Static exemptions:
 *  - `forms_data.brand_tag`     — brand label, tracked as parity-exempt
 *                                 in test_i18n.php (intentional en-only).
 *
 * If a new key is added without any of the above signals, this test fails.
 * Either reference the key, delete it, or document a new dynamic prefix
 * in the allowlist below.
 */

it('no unused i18n keys (modulo documented dynamic prefixes)', function () {
    $root = dirname(__DIR__, 2);
    $en = require $root . '/system/i18n/en.php';

    // Collect static t() / tn() references from PHP + JS + tests.
    $phpCode = '';
    foreach (['system', 'views', 'tests'] as $dir) {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
        foreach ($rii as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $phpCode .= file_get_contents($f->getPathname());
            }
        }
    }
    $jsCode = '';
    $jsDir = $root . '/public/assets/js';
    if (is_dir($jsDir)) {
        foreach (glob($jsDir . '/*.js') as $f) {
            $jsCode .= file_get_contents($f);
        }
    }

    // 1. `t('key')` / `tn('key', ...)` calls in PHP and JS.
    preg_match_all("/\\b(?:t|tn)\\(\\s*['\"]([a-z_][a-z0-9_.]*)/", $phpCode . $jsCode, $m);
    $used = array_flip($m[1]);

    // 2. Bare dotted string literals in JS — lookup tables and config maps
    //    forward these to `t()` indirectly (see task-page.js field→key map).
    preg_match_all("/['\"]([a-z_][a-z0-9_]*(?:\\.[a-z_][a-z0-9_]*){1,4})['\"]/", $jsCode, $m2);
    foreach ($m2[1] as $k) {
        $used[$k] = true;
    }

    // Dynamic prefixes — keep this list short and audited.
    $dynamicPrefixes = [
        'activity.',
        'errors.',
        'status.project.',
        'status.user.',
        'api_tokens.status_',
        'updates.kind.',
        'updates.source.',
        // Install wizard step labels — built as `'install.step.' . $key`
        // in views/layouts/install.php where $key ∈ {db, admin, security,
        // integrations, done}. The welcome step doesn't have a label here
        // (the step list is hidden on /install itself).
        'install.step.',
    ];

    // Static exemptions — keep tiny; each entry needs documentation above.
    $staticExempt = [
        'forms_data.brand_tag' => true,
    ];

    $unused = [];
    foreach (array_keys($en) as $k) {
        if (isset($used[$k]) || isset($staticExempt[$k])) {
            continue;
        }
        $isDynamic = false;
        foreach ($dynamicPrefixes as $p) {
            if (str_starts_with($k, $p)) {
                $isDynamic = true;
                break;
            }
        }
        if (!$isDynamic) {
            $unused[] = $k;
        }
    }

    assert_true(
        empty($unused),
        'Unused i18n keys (add t()/tn() reference, delete, or extend dynamic allowlist): '
            . implode(', ', array_slice($unused, 0, 15))
            . (count($unused) > 15 ? ' (+' . (count($unused) - 15) . ' more)' : '')
    );
});
