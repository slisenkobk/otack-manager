<?php
// Method-aware drift check between system/Api/V1/ApiKernel.php and
// docs/openapi.yaml. Substring matching is insufficient: a route registered
// as GET /api/v1/polls that drifted into POST /api/v1/polls in the YAML
// would slip through, because the *path* substring still matches. We
// extract (method, path) tuples from BOTH sources and require exact set
// equality.
//
// /api/v1/openapi.yaml is served inline by ApiKernel::serveOpenApi() (not
// in the $routes table) so it's whitelisted on the kernel side.

/**
 * Pull (method, path) tuples from ApiKernel — paths are normalised to
 * strip the /api/v1 prefix so they line up with the OpenAPI server-relative
 * convention.
 *
 * @return list<string> e.g. "get /projects", "post /projects/{id}/pin"
 */
function _drift_kernel_routes(string $kernelSrc): array {
    preg_match_all(
        "#\\\$this->routes\\['(GET|POST|PATCH|DELETE) (/api/v1/[^']+)'\\]#",
        $kernelSrc,
        $m
    );
    $out = [];
    foreach ($m[1] as $i => $method) {
        $path = preg_replace('#^/api/v1#', '', $m[2][$i]);
        // Multiple {…} placeholders in kernel routes are all spelled {id};
        // OpenAPI may use {user_id} or {other_id} — normalise so they align.
        $path = preg_replace('#\{[^}]+\}#', '{id}', $path);
        $out[] = strtolower($method) . ' ' . $path;
    }
    sort($out);
    return array_values(array_unique($out));
}

/**
 * Walk docs/openapi.yaml and pull (method, path) tuples from the `paths:`
 * section. We use a tiny line-aware parser instead of pulling in a YAML
 * library — the indent contract is uniform across the file.
 *
 * @return list<string> same shape as _drift_kernel_routes()
 */
function _drift_spec_routes(string $yaml): array {
    $lines = explode("\n", $yaml);
    $out = [];
    $currentPath = null;
    $inPaths = false;
    foreach ($lines as $line) {
        if (preg_match('/^paths:\s*$/', $line)) { $inPaths = true; continue; }
        if (!$inPaths) continue;
        // Any top-level key (no leading whitespace) closes the paths block.
        if (preg_match('/^[A-Za-z]/', $line)) { $inPaths = false; continue; }
        // Path lines look like `  /projects:` or `  /projects/{id}/pin:`.
        if (preg_match('#^  (/[^:]+):\s*$#', $line, $mm)) {
            $currentPath = preg_replace('#\{[^}]+\}#', '{id}', $mm[1]);
            continue;
        }
        // Verb lines look like `    get:` exactly 4 spaces deep.
        if ($currentPath && preg_match('/^    (get|post|patch|delete):\s*$/', $line, $mm)) {
            $out[] = $mm[1] . ' ' . $currentPath;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

it('every (method, path) in ApiKernel appears in docs/openapi.yaml', function () {
    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    $yaml      = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    $kernel    = _drift_kernel_routes($kernelSrc);
    $spec      = _drift_spec_routes($yaml);
    $missing   = array_values(array_diff($kernel, $spec));
    assert_true(empty($missing), 'missing from openapi.yaml: ' . implode(', ', $missing));
});

it('every (method, path) in docs/openapi.yaml appears in ApiKernel', function () {
    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    $yaml      = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    $kernel    = _drift_kernel_routes($kernelSrc);
    $spec      = _drift_spec_routes($yaml);
    // /openapi.yaml is served inline by ApiKernel::serveOpenApi() — not in
    // the $routes table — so it cannot appear in _drift_kernel_routes().
    // Whitelist that one tuple here.
    $spec = array_values(array_filter($spec, fn($r) => $r !== 'get /openapi.yaml'));
    $missing = array_values(array_diff($spec, $kernel));
    assert_true(empty($missing), 'missing from ApiKernel: ' . implode(', ', $missing));
});
