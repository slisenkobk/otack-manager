<?php
declare(strict_types=1);
namespace App\Service;

use App\Repository\UserRepository;

/**
 * Single code path for setting / removing a user's avatar. Used by the
 * self-service Profile page and by the admin user page, so validation,
 * storage and old-file cleanup are never duplicated.
 *
 * Every method takes the *actor* (who is acting) and the *target* user row;
 * the authorization rule lives in RolePolicy::canEditAvatar().
 */
final class AvatarService
{
    public function __construct(
        private FileUploader $uploader,
        private UserRepository $users,
    ) {}

    /** Returns null on success, or a human-readable error message. */
    public function replace(array $actor, array $target, ?array $file): ?string
    {
        if (!RolePolicy::canEditAvatar($actor, (int)$target['id'])) {
            return t('users.avatar_forbidden');
        }
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return t('profile.avatar_hint');
        }
        $err = $this->uploader->validate($file);
        if ($err) return $err;
        if (!$this->uploader->isImage($file['type'] ?? '')) {
            return t('profile.avatar_hint');
        }
        try {
            $stored = $this->uploader->store($file);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $this->deleteFile($target['avatar'] ?? null);
        $this->users->updateAvatar((int)$target['id'], $stored['filename']);
        return null;
    }

    /** Returns null on success (also when there was nothing to remove), or an error message. */
    public function remove(array $actor, array $target): ?string
    {
        if (!RolePolicy::canEditAvatar($actor, (int)$target['id'])) {
            return t('users.avatar_forbidden');
        }
        if (!empty($target['avatar'])) {
            $this->deleteFile($target['avatar']);
            $this->users->updateAvatar((int)$target['id'], null);
        }
        return null;
    }

    private function deleteFile(?string $avatar): void
    {
        if ($avatar === null || $avatar === '') return;
        // Only ever delete inside public/uploads, whatever the stored value says.
        $uploads = realpath(APP_ROOT . '/public/uploads');
        $abs = realpath(APP_ROOT . '/public/' . ltrim($avatar, '/'));
        if ($uploads === false || $abs === false || !str_starts_with($abs, $uploads . DIRECTORY_SEPARATOR)) return;
        if (is_file($abs)) @unlink($abs);
    }
}
