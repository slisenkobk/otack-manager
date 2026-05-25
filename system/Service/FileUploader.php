<?php
declare(strict_types=1);
namespace App\Service;

final class FileUploader
{
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const ALLOWED_OTHER = [
        'application/pdf',
        'application/zip',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/msword',
        'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint',
        'text/plain',
        'text/csv',
        'application/json',
        'application/xml',
        'text/xml',
        'video/mp4',
        'audio/mpeg',
    ];

    public function __construct(
        private int $maxImage,
        private int $maxFile,
        private string $uploadDir,
    ) {}

    public function isImage(string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    public function validate(array $file): ?string
    {
        $type = $file['type'] ?? '';
        $size = (int)($file['size'] ?? 0);

        if ($type === 'image/svg+xml') {
            return 'SVG uploads are not allowed';
        }

        if ($this->isImage($type)) {
            if ($size > $this->maxImage) {
                return 'Image exceeds ' . round($this->maxImage / 1048576) . ' MB';
            }
            return null;
        }

        if (in_array($type, self::ALLOWED_OTHER, true)) {
            if ($size > $this->maxFile) {
                return 'File exceeds ' . round($this->maxFile / 1048576) . ' MB';
            }
            return null;
        }

        return 'File type not allowed';
    }

    /** Move uploaded file and return canonical record. */
    public function store(array $file): array
    {
        // Sniff real mime via finfo
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream');

        // Validate again with real mime
        $err = $this->validate(['type' => $realMime, 'size' => $file['size'] ?? 0]);
        if ($err) {
            throw new \RuntimeException($err);
        }

        $ext    = $this->extensionFor($realMime, $file['name'] ?? '');
        $uuid   = bin2hex(random_bytes(16));
        $sub    = date('Y/m');
        $relDir = basename($this->uploadDir) . '/' . $sub;
        $absDir = $this->uploadDir . '/' . $sub;

        if (!is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }

        $filename = $relDir . '/' . $uuid . ($ext ? '.' . $ext : '');
        $absPath  = $this->uploadDir . '/' . $sub . '/' . $uuid . ($ext ? '.' . $ext : '');

        if (!move_uploaded_file($file['tmp_name'], $absPath)) {
            throw new \RuntimeException('Failed to move uploaded file');
        }

        return [
            'filename'      => $filename,
            'original_name' => $file['name'] ?? 'file',
            'mime'          => $realMime,
            'size'          => (int)($file['size'] ?? filesize($absPath)),
            'is_image'      => $this->isImage($realMime) ? 1 : 0,
        ];
    }

    private function extensionFor(string $mime, string $original): string
    {
        $map = [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'application/pdf'  => 'pdf',
            'application/zip'  => 'zip',
            'application/x-7z-compressed'   => '7z',
            'application/x-rar-compressed'  => 'rar',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/msword'        => 'doc',
            'application/vnd.ms-excel'  => 'xls',
            'application/vnd.ms-powerpoint' => 'ppt',
            'text/plain'        => 'txt',
            'text/csv'          => 'csv',
            'application/json'  => 'json',
            'application/xml'   => 'xml',
            'text/xml'          => 'xml',
            'video/mp4'         => 'mp4',
            'audio/mpeg'        => 'mp3',
        ];

        if (isset($map[$mime])) {
            return $map[$mime];
        }

        // Fall back to original extension if it's safe-looking
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (preg_match('/^[a-z0-9]{1,6}$/', $ext)) {
            return $ext;
        }

        return 'bin';
    }
}
