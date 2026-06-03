<?php
use App\Api\V1\ApiResponse;
use App\Api\V1\JsonRequest;

it('ApiResponse::ok returns serialised array + status', function () {
    $r = ApiResponse::ok(['x' => 1]);
    assert_eq(200, $r['status']);
    assert_eq('{"x":1}', $r['body']);
    assert_eq('application/json; charset=utf-8', $r['headers']['Content-Type']);
});

it('ApiResponse::created sets 201', function () {
    assert_eq(201, ApiResponse::created(['id' => 9])['status']);
});

it('ApiResponse::noContent sets 204 and empty body', function () {
    $r = ApiResponse::noContent();
    assert_eq(204, $r['status']);
    assert_eq('', $r['body']);
});

it('ApiResponse::error envelope shape', function () {
    $r = ApiResponse::error(422, 'validation_failed', 'Title is required', ['title' => 'required']);
    assert_eq(422, $r['status']);
    $body = json_decode($r['body'], true);
    assert_eq('validation_failed', $body['error']);
    assert_eq('Title is required', $body['message']);
    assert_eq(['title' => 'required'], $body['fields']);
});

it('ApiResponse::paginated wraps items + next_cursor', function () {
    $r = ApiResponse::paginated([['id' => 1], ['id' => 2]], 2);
    $body = json_decode($r['body'], true);
    assert_eq([['id' => 1], ['id' => 2]], $body['items']);
    assert_eq(2, $body['next_cursor']);
});

it('JsonRequest::parse returns array on valid JSON', function () {
    $req = JsonRequest::parse('{"a":1,"b":"x"}');
    assert_eq(['a' => 1, 'b' => 'x'], $req);
});

it('JsonRequest::parse throws on malformed JSON', function () {
    $threw = false;
    try { JsonRequest::parse('{bad'); }
    catch (\InvalidArgumentException $_) { $threw = true; }
    assert_true($threw);
});

it('JsonRequest::parse returns empty array for empty body', function () {
    assert_eq([], JsonRequest::parse(''));
});

it('JsonRequest::require pulls required fields, throws on missing', function () {
    $body = ['title' => 'X'];
    assert_eq('X', JsonRequest::requireString($body, 'title'));
    $threw = false;
    try { JsonRequest::requireString($body, 'missing'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_eq('missing', $e->getMessage()); }
    assert_true($threw);
});
