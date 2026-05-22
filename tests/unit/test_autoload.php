<?php
declare(strict_types=1);

it('autoloader resolves App\\Http\\Csrf', function (): void {
    assert_true(class_exists('App\\Http\\Csrf'), 'App\\Http\\Csrf should exist via autoloader');
});
