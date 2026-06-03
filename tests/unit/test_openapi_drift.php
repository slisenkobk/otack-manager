<?php
// Drift check between system/Api/V1/ApiKernel.php and docs/openapi.yaml.
// Substring-based — loose enough to allow distinct OpenAPI placeholder names
// (e.g. {user_id}, {tag_id}, {other_id}) while still catching add/remove of
// any route. /api/v1/openapi.yaml is served by the kernel inline (not in the
// routes table) so we whitelist it.

it('every route in ApiKernel appears in docs/openapi.yaml', function () {
    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    preg_match_all("#\\\$this->routes\\['(GET|POST|PATCH|DELETE) (/api/v1/[^']+)'\\]#", $kernelSrc, $m);
    $kernelRoutes = [];
    foreach ($m[1] as $i => $method) {
        $kernelRoutes[] = strtolower($method) . ' ' . $m[2][$i];
    }
    sort($kernelRoutes);
    $kernelRoutes = array_unique($kernelRoutes);

    $yaml = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    // Normalise YAML placeholders (e.g. {user_id}, {tag_id}, {other_id}) → {id}
    // so substring search against the kernel's all-{id} paths works.
    $yamlNorm = preg_replace('#\{[^}]+\}#', '{id}', $yaml);

    $missing = [];
    foreach ($kernelRoutes as $route) {
        [$method, $path] = explode(' ', $route, 2);
        // strip leading /api/v1 since OpenAPI uses server-relative paths
        $specPath = preg_replace('#^/api/v1#', '', $path);
        if (!str_contains($yamlNorm, $specPath)) {
            $missing[] = "$method $specPath";
        }
    }
    assert_true(empty($missing), "openapi.yaml missing routes: " . implode(', ', $missing));
});

it('every YAML path is in ApiKernel', function () {
    $yaml = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
    // Match `  /path:` lines (paths section entries) — they must start at
    // exactly 2 spaces of indent.
    preg_match_all('#^  (/[^:\n]+):#m', $yaml, $m);
    $specPaths = array_unique($m[1]);

    $kernelSrc = file_get_contents(dirname(__DIR__, 2) . '/system/Api/V1/ApiKernel.php');
    $missing = [];
    foreach ($specPaths as $p) {
        // /openapi.yaml is served inline by ApiKernel::serveOpenApi() — not in
        // the $routes table — so a direct kernel string match would fail.
        // Match against the inline branch instead.
        if ($p === '/openapi.yaml') {
            if (!str_contains($kernelSrc, "'/api/v1/openapi.yaml'")) {
                $missing[] = $p;
            }
            continue;
        }
        // Translate OpenAPI {foo} placeholders to the kernel's {id} normalisation.
        $needle = preg_replace('#\{[^}]+\}#', '{id}', $p);
        if (!str_contains($kernelSrc, '/api/v1' . $needle)) {
            $missing[] = $p;
        }
    }
    assert_true(empty($missing), "openapi.yaml has paths not in ApiKernel: " . implode(', ', $missing));
});
