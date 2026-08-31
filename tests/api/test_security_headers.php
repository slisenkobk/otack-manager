<?php
// Pins the Content-Security-Policy header sent by public/index.php. Regression
// coverage for Fix 1 (agreed 2026-08-27): `form-action` and `base-uri` were
// missing — neither falls back to `default-src`, so without them an injected
// <form action="https://evil"> could post off-site and an injected
// <base href="https://evil"> could re-point every relative URL on the page.
api_it('CSP header includes form-action and base-uri (and every prior directive)', function () {
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 5],
    ]);
    $body = @file_get_contents('http://localhost:8765/login', false, $ctx);
    assert_true($body !== false, 'GET /login should respond');
    $meta = $http_response_header ?? [];
    $csp = null;
    foreach ($meta as $h) {
        if (stripos($h, 'content-security-policy:') === 0) {
            $csp = trim(substr($h, strlen('content-security-policy:')));
        }
    }
    assert_true($csp !== null, 'Content-Security-Policy header must be present');

    $directives = array_map('trim', explode(';', (string)$csp));
    $names = array_map(function (string $d) {
        return trim(explode(' ', $d, 2)[0]);
    }, $directives);

    foreach ([
        'default-src', 'img-src', 'style-src-elem', 'style-src-attr',
        'script-src', 'font-src', 'connect-src', 'frame-ancestors',
        'form-action', 'base-uri',
    ] as $expected) {
        assert_true(in_array($expected, $names, true), "CSP missing directive: $expected (got: $csp)");
    }

    assert_true(str_contains((string)$csp, "form-action 'self'"), "form-action must be 'self' (got: $csp)");
    assert_true(str_contains((string)$csp, "base-uri 'self'"), "base-uri must be 'self' (got: $csp)");
});
