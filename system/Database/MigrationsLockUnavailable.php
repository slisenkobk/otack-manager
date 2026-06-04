<?php
declare(strict_types=1);
namespace App\Database;

/**
 * Thrown by Migrations::run() when the MySQL advisory lock
 * (GET_LOCK('otack_migrations', 30)) can't be acquired within the timeout —
 * almost always because another php-fpm worker is mid-apply on the same
 * deploy. The request-time caller (public/index.php) catches this and
 * continues silently; the CLI runner (bin/migrate.php) lets it propagate
 * so deploy scripts exit non-zero instead of falsely reporting "schema up
 * to date".
 */
final class MigrationsLockUnavailable extends \RuntimeException
{
}
