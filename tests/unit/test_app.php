<?php
declare(strict_types=1);

use App\App;

it('App::make resolves a registered singleton', function () {
    App::reset();
    App::singleton('greeter', fn() => new class {
        public function hello(): string { return 'hi'; }
    });
    $svc = App::make('greeter');
    assert_eq('hi', $svc->hello());
    // same instance on repeat call
    assert_true(App::make('greeter') === $svc);
});

it('App::make throws on unregistered key', function () {
    App::reset();
    $threw = false;
    try { App::make('nope'); }
    catch (\RuntimeException $_) { $threw = true; }
    assert_true($threw, 'expected RuntimeException for unregistered key');
});

it('App::make detects direct circular dependency (a -> a)', function () {
    App::reset();
    App::singleton('a', fn() => App::make('a'));
    $threw = false; $msg = '';
    try { App::make('a'); }
    catch (\LogicException $e) { $threw = true; $msg = $e->getMessage(); }
    assert_true($threw, 'expected LogicException for self-cycle');
    assert_true(str_contains($msg, 'circular'), "message should mention circular: $msg");
});

it('App::make detects indirect cycle (a -> b -> a)', function () {
    App::reset();
    App::singleton('a', fn() => App::make('b'));
    App::singleton('b', fn() => App::make('a'));
    $threw = false; $msg = '';
    try { App::make('a'); }
    catch (\LogicException $e) { $threw = true; $msg = $e->getMessage(); }
    assert_true($threw);
    assert_true(str_contains($msg, 'a -> b -> a'), "message should show chain: $msg");
});

it('App::reset clears resolving state so a later make can succeed', function () {
    App::reset();
    App::singleton('a', fn() => App::make('b'));
    App::singleton('b', fn() => App::make('a'));
    try { App::make('a'); } catch (\LogicException $_) {}
    // After a failed circular resolution, the resolving stack must NOT leak —
    // re-registering 'a' as a leaf and resolving it should now work.
    App::singleton('a', fn() => new \stdClass());
    App::reset();
    App::singleton('a', fn() => new \stdClass());
    $svc = App::make('a');
    assert_true($svc instanceof \stdClass);
});
