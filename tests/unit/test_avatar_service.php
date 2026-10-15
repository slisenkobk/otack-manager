<?php
use App\Database\Connection;
use App\Database\Migrations;
use App\Database\SchemaBootstrap;
use App\Repository\UserRepository;
use App\Service\AvatarService;
use App\Service\FileUploader;
use App\Service\RolePolicy;

it('canEditAvatar: admin may edit anyone', function () {
    assert_true(RolePolicy::canEditAvatar(['id' => 1, 'role' => 'admin'], 99));
});
it('canEditAvatar: non-admin may edit only self', function () {
    foreach (['manager', 'employee'] as $role) {
        assert_true(RolePolicy::canEditAvatar(['id' => 7, 'role' => $role], 7));
        assert_true(!RolePolicy::canEditAvatar(['id' => 7, 'role' => $role], 8));
    }
});

/** @return array{0:AvatarService,1:UserRepository,2:string,3:array,4:array,5:array} */
function _avatar_fixture(): array {
    $db = sys_get_temp_dir() . '/otack-avatar-' . uniqid() . '.sqlite';
    $pdo = Connection::open($db);
    Migrations::run(new SchemaBootstrap($pdo));
    $repo = new UserRepository($pdo);
    $adminId = $repo->create('admin@x', 'h', 'Admin');   // first user → admin
    $empId   = $repo->create('emp@x', 'h', 'Emp');
    $otherId = $repo->create('other@x', 'h', 'Other');
    foreach ([$empId, $otherId] as $id) { $repo->approve($id); $repo->setRole($id, 'employee'); }
    foreach ([$empId, $otherId] as $id) $repo->updateAvatar($id, 'uploads/does-not-exist.png');
    $svc = new AvatarService(new FileUploader(5_242_880, 52_428_800, sys_get_temp_dir() . '/otack-avatar-up'), $repo);
    return [$svc, $repo, $db, $repo->findById($adminId), $repo->findById($empId), $repo->findById($otherId)];
}

it('AvatarService::remove: non-admin cannot remove someone else\'s avatar', function () {
    [$svc, $repo, $db, $admin, $emp, $other] = _avatar_fixture();
    $err = $svc->remove($emp, $other);
    assert_true($err !== null);
    assert_eq('uploads/does-not-exist.png', $repo->findById((int)$other['id'])['avatar']);
    @unlink($db);
});

it('AvatarService::remove: admin can remove another user\'s avatar', function () {
    [$svc, $repo, $db, $admin, $emp, $other] = _avatar_fixture();
    assert_eq(null, $svc->remove($admin, $other));
    assert_eq(null, $repo->findById((int)$other['id'])['avatar']);
    @unlink($db);
});

it('AvatarService::remove: user can remove own avatar', function () {
    [$svc, $repo, $db, $admin, $emp] = _avatar_fixture();
    assert_eq(null, $svc->remove($emp, $emp));
    assert_eq(null, $repo->findById((int)$emp['id'])['avatar']);
    @unlink($db);
});

it('AvatarService::replace: non-admin forbidden for others, admin passes authz and hits shared validation', function () {
    [$svc, $repo, $db, $admin, $emp, $other] = _avatar_fixture();
    $forbidden = $svc->replace($emp, $other, ['error' => UPLOAD_ERR_OK, 'type' => 'image/png', 'size' => 10, 'tmp_name' => '/nonexistent']);
    assert_eq(t('users.avatar_forbidden'), $forbidden);
    // Admin gets past authorization and reaches the shared FileUploader validation (SVG rejected).
    $err = $svc->replace($admin, $other, ['error' => UPLOAD_ERR_OK, 'type' => 'image/svg+xml', 'size' => 10, 'tmp_name' => '/nonexistent']);
    assert_eq('SVG uploads are not allowed', $err);
    assert_eq('uploads/does-not-exist.png', $repo->findById((int)$other['id'])['avatar']);
    @unlink($db);
});
