<?php
use App\Service\FileUploader;

$dir = sys_get_temp_dir() . '/otack-uploads';

it('FileUploader: small JPEG valid', function () use ($dir) {
    $u = new FileUploader(5_242_880, 52_428_800, $dir);
    assert_eq(null, $u->validate(['type' => 'image/jpeg', 'size' => 1_000_000]));
});

it('FileUploader: oversize JPEG fails', function () use ($dir) {
    $u = new FileUploader(5_242_880, 52_428_800, $dir);
    $err = $u->validate(['type' => 'image/jpeg', 'size' => 6_000_000]);
    assert_true(str_contains($err, 'Image exceeds'));
});

it('FileUploader: oversize PDF fails', function () use ($dir) {
    $u = new FileUploader(5_242_880, 52_428_800, $dir);
    $err = $u->validate(['type' => 'application/pdf', 'size' => 60_000_000]);
    assert_true(str_contains($err, 'File exceeds'));
});

it('FileUploader: SVG rejected', function () use ($dir) {
    $u = new FileUploader(5_242_880, 52_428_800, $dir);
    assert_eq('SVG uploads are not allowed', $u->validate(['type' => 'image/svg+xml', 'size' => 1000]));
});

it('FileUploader: unknown mime rejected', function () use ($dir) {
    $u = new FileUploader(5_242_880, 52_428_800, $dir);
    assert_eq('File type not allowed', $u->validate(['type' => 'application/x-php', 'size' => 1000]));
});
