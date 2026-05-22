<?php
declare(strict_types=1);

use App\View\Renderer;

it('renders template with vars and layout', function () {
    $tmp = sys_get_temp_dir() . '/views-' . uniqid();
    mkdir($tmp . '/layouts', 0755, true);
    mkdir($tmp . '/page', 0755, true);
    file_put_contents($tmp . '/layouts/main.php', '<x><?= $content ?></x>');
    file_put_contents($tmp . '/page/hi.php', 'hello <?= e($name) ?>');
    $r = new Renderer($tmp);
    $out = $r->render('page/hi', ['name' => '<b>x</b>'], 'layouts/main');
    assert_eq('<x>hello &lt;b&gt;x&lt;/b&gt;</x>', $out);
    // cleanup
    @unlink($tmp . '/layouts/main.php');
    @unlink($tmp . '/page/hi.php');
    @rmdir($tmp . '/layouts');
    @rmdir($tmp . '/page');
    @rmdir($tmp);
});

it('e() escapes', function () {
    require_once APP_ROOT . '/system/View/helpers.php';
    assert_eq('&lt;a&gt;', e('<a>'));
});
