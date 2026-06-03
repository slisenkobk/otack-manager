<?php
use App\Service\EventBus;

it('EventBus::fire invokes a registered listener', function () {
    $bus = new EventBus();
    $seen = null;
    $bus->on('thing.happened', function (array $p) use (&$seen) { $seen = $p; });
    $bus->fire('thing.happened', ['x' => 1]);
    assert_eq(['x' => 1], $seen);
});

it('EventBus::fire invokes all listeners for the same event', function () {
    $bus = new EventBus();
    $calls = [];
    $bus->on('e', function (array $p) use (&$calls) { $calls[] = 'a:' . $p['v']; });
    $bus->on('e', function (array $p) use (&$calls) { $calls[] = 'b:' . $p['v']; });
    $bus->fire('e', ['v' => 7]);
    assert_eq(['a:7', 'b:7'], $calls);
});

it('EventBus::fire with no listeners is a no-op', function () {
    $bus = new EventBus();
    $threw = false;
    try { $bus->fire('nothing.listens', ['a' => 1]); }
    catch (\Throwable) { $threw = true; }
    assert_true(!$threw);
});

it('EventBus::fire swallows listener exceptions (doesn\'t halt others)', function () {
    // Defensive: a faulty listener must not break the chain — Log::error
    // captures it and we keep going. We assert the second listener still runs.
    $bus = new EventBus();
    $ran = false;
    $bus->on('e', function () { throw new \RuntimeException('boom'); });
    $bus->on('e', function () use (&$ran) { $ran = true; });
    $bus->fire('e', []);
    assert_true($ran);
});
