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
     */
    public static function run(SchemaBootstrap $boot, ?string $dir = null): array
    {
        $dir = $dir ?? self::DIR;
        $files = glob($dir . '/*.php') ?: [];
        if (!$files) return [];
        sort($files);

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
        }
        return $applied;
    }
}
