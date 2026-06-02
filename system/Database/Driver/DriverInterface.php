<?php
declare(strict_types=1);
namespace App\Database\Driver;

/**
 * Driver contract — deliberately narrow. The rule (docs/DATABASE.md §3.1):
 * anything not on this interface must be expressible in plain SQL that
 * runs on both supported drivers without per-driver branching.
 *
 * The interface grows in later steps:
 *   - step 2 adds compileBlueprint() once the Schema DSL lands;
 *   - step 4 adds snapshot() once the per-driver backup adapters arrive.
 *
 * This file represents the minimal surface needed to OPEN a connection.
 */
interface DriverInterface
{
    /** Short driver tag, used in conditionals and log lines. 'sqlite' | 'mysql'. */
    public function name(): string;

    /** Full PDO DSN string passed to `new PDO(...)`. */
    public function dsn(): string;

    /** Username for the PDO constructor, or null when not applicable (SQLite). */
    public function username(): ?string;

    /** Password for the PDO constructor, or null. */
    public function password(): ?string;

    /**
     * PDO attribute bag merged onto the connection. Must include at least
     * ATTR_ERRMODE => EXCEPTION and ATTR_DEFAULT_FETCH_MODE => FETCH_ASSOC
     * so the rest of the codebase can rely on those.
     */
    public function pdoOptions(): array;

    /**
     * Driver-specific bootstrap to run immediately after `new PDO(...)`.
     * SQLite uses this to set PRAGMAs (foreign_keys, journal_mode, busy_timeout);
     * MySQL uses it to set NAMES / collation / sql_mode.
     */
    public function postConnect(\PDO $pdo): void;

    /**
     * Compile a Blueprint into a list of CREATE TABLE + CREATE INDEX
     * statements. The list is executed top-to-bottom by Schema; each
     * element is one stand-alone statement (no semicolons inside).
     *
     * @return string[]
     */
    public function compileCreateTable(\App\Database\Schema\Blueprint $bp): array;

    /**
     * Compile a Blueprint that describes changes to an existing table
     * (column additions + new indexes). Same return shape as above.
     *
     * @return string[]
     */
    public function compileAlterTable(\App\Database\Schema\Blueprint $bp): array;
}
