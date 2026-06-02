<?php
declare(strict_types=1);

/**
 * Convention test — prevents regressions on the dual-driver portability
 * work from docs/DATABASE.md §5. Scans system/Repository/*.php for
 * SQLite-only tokens that would silently break on MySQL.
 *
 * If a new repo legitimately needs one of these (because the driver
 * abstraction can't yet express it), the right fix is to extend
 * DriverInterface and call the abstracted helper from the repo — not
 * to allowlist the token here.
 */

it('Repositories do not embed SQLite-only SQL fragments', function () {
    $root = dirname(__DIR__, 2) . '/system/Repository';
    $files = glob($root . '/*.php') ?: [];
    assert_true(count($files) > 0, 'expected at least one repo file under system/Repository');

    // Banned tokens. Each line lists the token + a one-line "why it's banned"
    // so the failure message points the contributor at the fix.
    $banned = [
        'LIMIT -1'         => 'use $driver->paginationAllOffsetSql() (SQLite-only)',
        'INSERT OR IGNORE' => 'use $driver->insertIgnoreVerb() (MySQL spells it INSERT IGNORE)',
        'INSERT OR REPLACE'=> 'REPLACE INTO on MySQL — abstract via driver if you need it',
        'julianday('       => 'compute dates in PHP and compare ISO strings instead',
        'sqlite_master'    => 'use $driver->listTablesSql() — MySQL uses information_schema',
        "PRAGMA "          => 'PRAGMAs are SQLite-only; move into Driver::postConnect',
    ];

    $violations = [];
    foreach ($files as $f) {
        $body = (string)file_get_contents($f);
        foreach ($banned as $needle => $why) {
            if (stripos($body, $needle) !== false) {
                $violations[] = basename($f) . ' contains "' . $needle . '" — ' . $why;
            }
        }
    }
    assert_true(
        $violations === [],
        "Forbidden SQLite-only fragments found:\n  - " . implode("\n  - ", $violations)
    );
});

it('Schema migrations do not embed SQLite-only fragments', function () {
    $root = dirname(__DIR__, 2) . '/system/Database/migrations';
    $files = glob($root . '/*.php') ?: [];
    assert_true(count($files) >= 20, 'sanity: at least 20 migrations expected');

    // Migrations have a wider scope than repos (they own DDL), but a
    // small allowlist still applies — DDL goes through the DSL.
    $banned = [
        'PRAGMA table_info' => 'use createTable/alterTable + schema_migrations idempotency',
        'datetime(\'now\')'  => 'use PHP-side ISO timestamps (Y-m-d H:i:s)',
        ' AUTOINCREMENT'    => 'use $t->id() — the DSL maps it per driver',
    ];

    $violations = [];
    foreach ($files as $f) {
        $body = (string)file_get_contents($f);
        foreach ($banned as $needle => $why) {
            if (stripos($body, $needle) !== false) {
                $violations[] = basename($f) . ' contains "' . trim($needle) . '" — ' . $why;
            }
        }
    }
    assert_true(
        $violations === [],
        "Forbidden raw SQL in migrations:\n  - " . implode("\n  - ", $violations)
    );
});

it('Connection layer can refuse unsupported DSN schemes', function () {
    // Reaffirms the DriverFactory contract — if someone wires in a new
    // driver, this test forces them to think about the error message.
    $threw = false;
    try {
        \App\Database\Driver\DriverFactory::make('pgsql:host=localhost;dbname=x');
    } catch (\InvalidArgumentException $e) {
        $threw = strpos($e->getMessage(), 'sqlite, mysql') !== false;
    }
    assert_true($threw, 'Adding a new driver must update the factory error message too');
});
