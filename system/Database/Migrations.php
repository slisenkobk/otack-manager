<?php
declare(strict_types=1);
namespace App\Database;

// Per-file migrations runner. Discovers `*.php` files under $dir, applies
// any whose basename (sans `.php`) is not yet in `schema_migrations`, and
// wraps the batch in BEGIN IMMEDIATE so concurrent first hits serialise
// instead of racing.
//
// Filenames are PERMANENT once shipped: renaming an applied migration
// would make the runner re-execute it. See docs/MIGRATIONS.md.
final class Migrations
{
    public const DIR = __DIR__ . '/migrations';

    /**
     * Returns the list of newly-applied migration names (empty when up to date).
     *
     * On MySQL, takes a named advisory lock (GET_LOCK) before the BEGIN so
     * concurrent php-fpm worker boots serialise on the lock instead of
     * racing past the empty schema_migrations table. SQLite's BEGIN IMMEDIATE
     * provides the equivalent guarantee — single-writer at the file level.
     */
    public static function run(SchemaBootstrap $boot, ?string $dir = null): array
    {
        $dir = $dir ?? self::DIR;
        $files = glob($dir . '/*.php') ?: [];
        if (!$files) return [];
        sort($files);

        $pdo    = $boot->pdo();
        $driver = Connection::driverFor($pdo)?->name() ?? 'sqlite';
        if ($driver === 'mysql') {
            $got = (int)$pdo->query("SELECT GET_LOCK('otack_migrations', 30)")->fetchColumn();
            // 0 = waited 30s and gave up; NULL = error. Either way another
            // worker is mid-apply; bail out — once it finishes, our boot has
            // already proceeded with a stale appliedSet snapshot but the
            // applied set inside the lock is authoritative for the lock
            // holder, and any subsequent boot will see the rows committed.
            if ($got !== 1) return [];
        }

        $boot->beginImmediate();
        $applied = [];
        try {
            $known = $boot->appliedSet();
            foreach ($files as $file) {
                $name = basename($file, '.php');
                if (isset($known[$name])) continue;
                $boot->runFile($file);
                $applied[] = $name;
                // 0000 creates the schema_migrations table on the very first
                // boot and may have backfilled rows from data/.schema/. Refresh
                // the known set so subsequent files in this batch see those.
                if ($name === '0000_schema_migrations') {
                    $known = $boot->appliedSet();
                }
            }
            $boot->commit();
        } catch (\Throwable $e) {
            $boot->rollBack();
            throw $e;
        } finally {
            if ($driver === 'mysql') {
                try { $pdo->query("SELECT RELEASE_LOCK('otack_migrations')"); }
                catch (\Throwable $_) {}
            }
        }
        return $applied;
    }
}
