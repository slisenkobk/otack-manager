<?php
declare(strict_types=1);
namespace App\Database\Snapshot;

/**
 * Backup / restore for MySQL via `mysqldump` and `mysql` from the host
 * PATH. Single-transaction dumps so the snapshot is consistent without
 * an explicit lock; gzipped on the way out to keep `data/backups/`
 * compact.
 *
 * Requires:
 *   - mysqldump on PATH (or MYSQLDUMP_PATH env)
 *   - mysql on PATH      (or MYSQL_PATH env)
 *
 * Errors are surfaced loudly: the Updater is expected to refuse to run
 * on a MySQL host when these aren't available.
 */
final class MysqlSnapshot implements SnapshotInterface
{
    /**
     * @param array{host:string,port:int,db:string,user:?string,password:?string} $conn
     */
    public function __construct(private array $conn) {}

    public function fileExtension(): string { return 'sql.gz'; }

    public function backupTo(string $destPath): void
    {
        $bin = $this->mysqldumpBinary();
        $cmd = sprintf(
            '%s --single-transaction --quick --routines --triggers '
            . '--default-character-set=utf8mb4 '
            . '--host=%s --port=%d %s %s | gzip > %s',
            escapeshellcmd($bin),
            escapeshellarg($this->conn['host']),
            (int)$this->conn['port'],
            $this->credentialArgs(),
            escapeshellarg($this->conn['db']),
            escapeshellarg($destPath)
        );
        $ret = 0;
        passthru($cmd . ' 2>&1', $ret);
        if ($ret !== 0 || !is_file($destPath) || filesize($destPath) === 0) {
            throw new \RuntimeException("MysqlSnapshot: mysqldump failed (exit=$ret)");
        }
    }

    public function restoreFrom(string $srcPath): void
    {
        if (!is_file($srcPath)) {
            throw new \RuntimeException("MysqlSnapshot: snapshot file missing: $srcPath");
        }
        $bin = $this->mysqlBinary();
        $cmd = sprintf(
            'gunzip -c %s | %s --host=%s --port=%d %s %s',
            escapeshellarg($srcPath),
            escapeshellcmd($bin),
            escapeshellarg($this->conn['host']),
            (int)$this->conn['port'],
            $this->credentialArgs(),
            escapeshellarg($this->conn['db'])
        );
        $ret = 0;
        passthru($cmd . ' 2>&1', $ret);
        if ($ret !== 0) {
            throw new \RuntimeException("MysqlSnapshot: mysql restore failed (exit=$ret)");
        }
    }

    private function mysqldumpBinary(): string
    {
        return getenv('MYSQLDUMP_PATH') ?: 'mysqldump';
    }

    private function mysqlBinary(): string
    {
        return getenv('MYSQL_PATH') ?: 'mysql';
    }

    private function credentialArgs(): string
    {
        $u = $this->conn['user'] ?? null;
        $p = $this->conn['password'] ?? null;
        $parts = [];
        if ($u !== null && $u !== '') $parts[] = '--user=' . escapeshellarg($u);
        if ($p !== null && $p !== '') $parts[] = '--password=' . escapeshellarg($p);
        return implode(' ', $parts);
    }
}
