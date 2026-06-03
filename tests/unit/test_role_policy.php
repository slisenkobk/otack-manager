<?php
use App\Service\RolePolicy;

it('canDeleteComment admin always', function () {
    $u = ['id' => 1, 'role' => 'admin'];
    $c = ['user_id' => 99];
    assert_true(RolePolicy::canDeleteComment($u, $c));
});

it('canDeleteComment author', function () {
    $u = ['id' => 7, 'role' => 'employee'];
    $c = ['user_id' => 7];
    assert_true(RolePolicy::canDeleteComment($u, $c));
});

it('canDeleteComment not author and not admin → false', function () {
    $u = ['id' => 7, 'role' => 'employee'];
    $c = ['user_id' => 8];
    assert_true(!RolePolicy::canDeleteComment($u, $c));
});
