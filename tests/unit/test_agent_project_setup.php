<?php
$__lib = dirname(__DIR__, 2) . '/agent/lib/ProjectSetup.php';
if (!is_file($__lib)) { it('agent/lib/ProjectSetup.php exists', fn() => assert_true(false, 'not implemented yet')); return; }
require_once $__lib;
use App\Agent\ProjectSetup;

it('slugify transliterates Ukrainian, Russian and Polish', function () {
    assert_eq('tili-tisto', ProjectSetup::slugify('Тілі-Тісто'));
    assert_eq('zhytomyrska-pekarnia', ProjectSetup::slugify('Житомирська пекарня'));
    assert_eq('zch-gora', ProjectSetup::slugify('ZCH Góra'));
    assert_eq('zolta-lodz', ProjectSetup::slugify('Żółta łódź'));
    assert_eq('', ProjectSetup::slugify('!!!'));
    $long = ProjectSetup::slugify(str_repeat('abc ', 40));
    assert_true(strlen($long) <= 48 && substr($long, -1) !== '-', $long);
    foreach (['  --Abc--  ', 'Тілі-', '-x-', str_repeat('abc ', 40)] as $in) {
        $r = ProjectSetup::slugify($in);
        assert_true($r === '' || ($r[0] !== '-' && substr($r, -1) !== '-'), "'$in' -> '$r'");
    }
});

it('folderName prefers the repo name, then slug, then name, then project-<id>', function () {
    assert_eq('otack-cms', ProjectSetup::folderName(['id' => 1, 'repo_url' => 'git@github.com:AINeoLab/otack-cms.git', 'slug' => 'x', 'name' => 'y']));
    assert_eq('otack-cms', ProjectSetup::folderName(['id' => 1, 'repo_url' => 'https://github.com/AINeoLab/Otack-CMS/']));
    assert_eq('website-redesign', ProjectSetup::folderName(['id' => 1, 'repo_url' => null, 'slug' => 'website-redesign', 'name' => 'y']));
    assert_eq('tili-tisto', ProjectSetup::folderName(['id' => 1, 'slug' => '', 'name' => 'Тілі-Тісто']));
    assert_eq('project-17', ProjectSetup::folderName(['id' => 17, 'name' => '???']));
    assert_true((bool)preg_match(ProjectSetup::FOLDER_PATTERN, ProjectSetup::folderName(['id' => 2, 'name' => 'Ёжик в тумане'])));
});

it('sameRepo treats ssh, https and scp-like forms of one repo as equal', function () {
    $forms = ['git@github.com:AINeoLab/otack-cms.git', 'https://github.com/AINeoLab/otack-cms', 'ssh://git@github.com:22/AINeoLab/otack-cms.git', 'https://user@GitHub.com/AINeoLab/otack-cms.git/'];
    foreach ($forms as $a) { foreach ($forms as $b) { assert_true(ProjectSetup::sameRepo($a, $b), "$a vs $b"); } }
    assert_true(!ProjectSetup::sameRepo('git@github.com:AINeoLab/otack-cms.git', 'git@github.com:AINeoLab/otack-cms-fork.git'));
    assert_true(!ProjectSetup::sameRepo('git@github.com:AINeoLab/otack-cms.git', 'git@gitlab.com:AINeoLab/otack-cms.git'));
});

it('originOf reads a real checkout and returns null for a plain folder', function () {
    $d = sys_get_temp_dir() . '/otack-ps-' . bin2hex(random_bytes(4));
    mkdir($d);
    assert_eq(null, ProjectSetup::originOf($d));
    shell_exec('git -C ' . escapeshellarg($d) . ' init -q && git -C ' . escapeshellarg($d) . ' remote add origin git@github.com:o/r.git');
    assert_eq('git@github.com:o/r.git', ProjectSetup::originOf($d));
    shell_exec('rm -rf ' . escapeshellarg($d));
});

it('cloneInto clones a local repo and fails with git\'s message on a bad url', function () {
    $src = sys_get_temp_dir() . '/otack-ps-src-' . bin2hex(random_bytes(4));
    mkdir($src);
    shell_exec('git -C ' . escapeshellarg($src) . ' init -q && git -C ' . escapeshellarg($src) . ' -c user.email=t@t -c user.name=t commit -q --allow-empty -m init');
    $dst = sys_get_temp_dir() . '/otack-ps-dst-' . bin2hex(random_bytes(4));
    ProjectSetup::cloneInto($src, $dst);
    assert_true(is_dir("$dst/.git"));
    try { ProjectSetup::cloneInto('/nonexistent-' . bin2hex(random_bytes(3)), $dst . '-2'); assert_true(false, 'expected throw'); }
    catch (RuntimeException $e) { assert_true(str_contains($e->getMessage(), 'git clone'), $e->getMessage()); }
    finally { shell_exec('rm -rf ' . escapeshellarg($src) . ' ' . escapeshellarg($dst) . ' ' . escapeshellarg($dst . '-2')); }
});
