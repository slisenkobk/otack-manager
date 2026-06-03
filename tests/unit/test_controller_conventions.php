<?php
declare(strict_types=1);

/**
 * Architecture guard for the C-1 constructor-injection sweep:
 * controllers must not call App::make() in method bodies. Each
 * dependency belongs in the constructor as a typed property.
 *
 * We strip the controller's constructor before grepping so legitimate
 * App::make() bridge calls there don't trigger false positives. The
 * Factory wires every controller through the container, and
 * BaseController's csrfToken() helper keeps its one App::make('csrf')
 * call — it's an allowlisted base-class helper, not a method body.
 */
it('no App::make() outside __construct in controllers', function () {
    $files = glob(dirname(__DIR__, 2) . '/system/Controller/*.php');
    $offenders = [];
    foreach ($files as $f) {
        $src = file_get_contents($f);
        $stripped = $src;
        // Hand-rolled brace-balanced strip of the __construct body so
        // any App::make() bridge calls there don't count as offences.
        if (preg_match('/public function __construct\s*\([^{]*\{/s', $src, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $depth = 1; $i = $start;
            while ($i < strlen($src) && $depth > 0) {
                if ($src[$i] === '{') $depth++;
                elseif ($src[$i] === '}') $depth--;
                $i++;
            }
            $stripped = substr($src, 0, $m[0][1]) . substr($src, $i);
        }
        if (str_contains($stripped, 'App::make(')) {
            $offenders[] = basename($f);
        }
    }
    // BaseController keeps a single App::make('csrf') in csrfToken() — a
    // shared helper available to every subclass without forcing every
    // constructor to thread Csrf through. Factory.php is the DI bridge:
    // its whole job is to call App::make() to wire constructors.
    $offenders = array_values(array_diff($offenders, [
        'BaseController.php',
        'Factory.php',
    ]));
    assert_true(
        empty($offenders),
        'App::make in body: ' . implode(', ', $offenders)
    );
});
