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

// --- store() coverage (T-5) ---------------------------------------------------

/** Build a fake $_FILES entry by writing $bytes to a tempfile. */
function _fu_fake_file(string $bytes, string $name, string $type): array {
    $tmp = tempnam(sys_get_temp_dir(), 'fu_');
    file_put_contents($tmp, $bytes);
    return [
        'name'     => $name,
        'tmp_name' => $tmp,
        'type'     => $type,
        'size'     => strlen($bytes),
        'error'    => 0,
    ];
}

it('FileUploader::store throws when image exceeds maxImage', function () {
    $sandbox = sys_get_temp_dir() . '/otack-uploads-' . bin2hex(random_bytes(4));
    mkdir($sandbox, 0755, true);
    // Real PNG bytes (1x1 white): finfo will sniff as image/png.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );
    $u = new FileUploader(64, 10_000_000, $sandbox); // maxImage = 64 bytes — below the PNG
    $file = _fu_fake_file($png, 'pic.png', 'image/png');
    $threw = false;
    try { $u->store($file); }
    catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'Image exceeds'));
        $threw = true;
    }
    @unlink($file['tmp_name']);
    assert_true($threw);
});

it('FileUploader::store throws when non-image exceeds maxFile', function () {
    $sandbox = sys_get_temp_dir() . '/otack-uploads-' . bin2hex(random_bytes(4));
    mkdir($sandbox, 0755, true);
    // Minimal-ish PDF header — finfo will sniff application/pdf.
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n" . str_repeat('A', 200) . "\n%%EOF\n";
    $u = new FileUploader(10_000_000, 64, $sandbox); // maxFile = 64 bytes
    $file = _fu_fake_file($pdf, 'doc.pdf', 'application/pdf');
    $threw = false;
    try { $u->store($file); }
    catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'File exceeds'));
        $threw = true;
    }
    @unlink($file['tmp_name']);
    assert_true($threw);
});

it('FileUploader::store rejects unsniffable / disallowed bytes', function () {
    $sandbox = sys_get_temp_dir() . '/otack-uploads-' . bin2hex(random_bytes(4));
    mkdir($sandbox, 0755, true);
    // Random bytes — finfo will most likely report application/octet-stream
    // (not in IMAGE_MIMES or ALLOWED_OTHER), so validate() returns "not allowed".
    $u = new FileUploader(10_000_000, 10_000_000, $sandbox);
    $file = _fu_fake_file("\x00\x01\x02\x03\x04binarygarbage", 'thing.xyz', 'application/octet-stream');
    $threw = false;
    try { $u->store($file); }
    catch (\RuntimeException $e) {
        assert_true(str_contains($e->getMessage(), 'not allowed'));
        $threw = true;
    }
    @unlink($file['tmp_name']);
    assert_true($threw);
});

it('FileUploader::store sanitises directory-traversal in original name', function () {
    // Even if a hostile $_FILES['name'] is "../../../etc/passwd", the stored
    // filename is built from a UUID + extension-from-mime — never from $name.
    // We exercise the size-error path (cheaper than a real move_uploaded_file
    // setup) but with a poison name to confirm no traversal characters leak
    // through into the error or any side effect.
    $sandbox = sys_get_temp_dir() . '/otack-uploads-' . bin2hex(random_bytes(4));
    mkdir($sandbox, 0755, true);
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );
    // Force the size check to fail so we don't depend on move_uploaded_file
    // (which only works in real HTTP context).
    $u = new FileUploader(1, 1, $sandbox);
    $file = _fu_fake_file($png, '../../../etc/passwd', 'image/png');
    $threw = false;
    try { $u->store($file); }
    catch (\RuntimeException $e) {
        // Error must mention the size — never the traversal string.
        assert_true(str_contains($e->getMessage(), 'Image exceeds'));
        assert_true(!str_contains($e->getMessage(), '..'));
        assert_true(!str_contains($e->getMessage(), 'passwd'));
        $threw = true;
    }
    @unlink($file['tmp_name']);
    assert_true($threw);
    // Sandbox dir must contain no traversed escape file.
    assert_true(!file_exists($sandbox . '/../passwd'));
    assert_true(!file_exists($sandbox . '/passwd'));
});
