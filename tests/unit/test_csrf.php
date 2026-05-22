<?php
declare(strict_types=1);

use App\Http\Csrf;

it('generates a token and verifies it', function () {
    $store = [];
    $c = new Csrf($store);
    $t = $c->token();
    assert_true(strlen($t) >= 32);
    assert_true($c->verify($t));
    assert_true(!$c->verify('wrong'));
});

it('reuses token within the same instance', function () {
    $store = [];
    $c = new Csrf($store);
    assert_eq($c->token(), $c->token());
});

it('regenerate gives a new token', function () {
    $store = [];
    $c = new Csrf($store);
    $a = $c->token();
    $c->regenerate();
    assert_true($a !== $c->token());
});
