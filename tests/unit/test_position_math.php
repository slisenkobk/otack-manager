<?php
use App\Repository\TaskRepository;

it('computePosition: both null → 1024', function () {
    assert_eq(1024.0, TaskRepository::computePosition(null, null));
});
it('computePosition: prev only → prev + 1024', function () {
    assert_eq(1034.0, TaskRepository::computePosition(10.0, null));
});
it('computePosition: next only → next - 1024', function () {
    assert_eq(-1014.0, TaskRepository::computePosition(null, 10.0));
});
it('computePosition: midpoint', function () {
    assert_eq(15.0, TaskRepository::computePosition(10.0, 20.0));
});
