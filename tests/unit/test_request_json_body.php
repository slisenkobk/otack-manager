<?php
declare(strict_types=1);

use App\Http\Request;

function _req(string $method = 'POST'): Request {
    return new Request($method, '/', [], [], [], [], []);
}

it('jsonBody returns [] on empty body when no default', function () {
    $r = _req();
    assert_eq([], $r->jsonBody(null, ''));
});

it('jsonBody returns the default on empty body when default is set', function () {
    $r = _req();
    assert_eq(['fallback' => true], $r->jsonBody(['fallback' => true], ''));
});

it('jsonBody parses valid JSON object', function () {
    $r = _req();
    assert_eq(['a' => 1, 'b' => 'x'], $r->jsonBody(null, '{"a":1,"b":"x"}'));
});

it('jsonBody parses valid JSON list', function () {
    $r = _req();
    assert_eq([1, 2, 3], $r->jsonBody(null, '[1,2,3]'));
});

it('jsonBody throws InvalidArgumentException on malformed JSON when no default', function () {
    $r = _req();
    $threw = false;
    try { $r->jsonBody(null, '{not-valid'); }
    catch (\InvalidArgumentException $_) { $threw = true; }
    assert_true($threw, 'expected InvalidArgumentException on malformed body');
});

it('jsonBody returns default on malformed JSON when default is set', function () {
    $r = _req();
    assert_eq(['fallback' => 1], $r->jsonBody(['fallback' => 1], '{not-valid'));
});

it('jsonBody treats JSON null as malformed', function () {
    // json_decode("null") returns null (not an array). Same path as malformed.
    $r = _req();
    $threw = false;
    try { $r->jsonBody(null, 'null'); }
    catch (\InvalidArgumentException $_) { $threw = true; }
    assert_true($threw);
});
