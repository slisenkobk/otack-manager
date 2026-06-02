<?php
declare(strict_types=1);
namespace App\Database\Snapshot;

/**
 * Backup / restore for MySQL via `mysqldump` and `mysql` from the host
 * PATH. Single-transaction dumps so the snapshot is consistent without
 * an explicit lock; gzipped on the way out to keep `data/backups/`
 * compact.
 *
 * The password is passed via the MYSQL_PWD environment variable rather
 * than `--password=` on the command line — the latter is visible to
 * any user on the host via `ps`. Both calls use proc_open so we control
 * the env block precisely.
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
     * @param array{host:?string,port:int,db:string,user:?string,password:?string,socket?:?string} $conn
     */
    public function __construct(private array $conn) {}

    public function fileExtension(): string { return 'sql.gz'; }

    public function backupTo(string $destPath): void
    {
        $args = array_merge(
            [$this->mysqldumpBinary(), '--single-transaction', '--quick', '--routines', '--triggers',
             '--default-character-set=utf8mb4'],
            $this->connectionArgs(),
            [$this->conn['db']]
        );
        // mysqldump → gzip → file. proc_open with a pipe pair gives us
        // both control over the env (MYSQL_PWD) and a portable file
        // redirect without shell interpolation of the args.
        $this->runPipeline($args, ['gzip'], $destPath, 'mysqldump');
        if (!is_file($destPath) || filesize($destPath) === 0) {
            throw new \RuntimeException('MysqlSnapshot: mysqldump produced empty file');
        }
    }

    public function restoreFrom(string $srcPath): void
    {
        if (!is_file($srcPath)) {
            throw new \RuntimeException("MysqlSnapshot: snapshot file missing: $srcPath");
        }
        // gunzip < $srcPath → mysql client (with credentials via env).
        $args = array_merge(
            [$this->mysqlBinary()],
            $this->connectionArgs(),
            [$this->conn['db']]
        );
        $this->runRestore(['gunzip', '-c', $srcPath], $args);
    }

    private function mysqldumpBinary(): string
    {
        return getenv('MYSQLDUMP_PATH') ?: 'mysqldump';
    }

    private function mysqlBinary(): string
    {
        return getenv('MYSQL_PATH') ?: 'mysql';
    }

    /**
     * Connection flags — uses --socket=... when a unix socket is set
     * (matches the PDO connection), otherwise --host + --port.
     * --user goes on the command line (visible in ps, but usernames
     * aren't secrets); password is omitted here and passed via env.
     *
     * @return string[]
     */
    private function connectionArgs(): array
    {
        $args = [];
        if (!empty($this->conn['socket'])) {
            $args[] = '--socket=' . $this->conn['socket'];
        } else {
            $args[] = '--host=' . ($this->conn['host'] ?? '127.0.0.1');
            $args[] = '--port=' . (int)$this->conn['port'];
        }
        if (!empty($this->conn['user'])) {
            $args[] = '--user=' . $this->conn['user'];
        }
        return $args;
    }

    /** Env block passed to proc_open. MYSQL_PWD must not be inherited. */
    private function envBlock(): array
    {
        $env = [];
        foreach (['PATH', 'LANG', 'LC_ALL', 'HOME', 'TMPDIR'] as $k) {
            $v = getenv($k);
            if ($v !== false) $env[$k] = $v;
        }
        $pw = $this->conn['password'] ?? null;
        if ($pw !== null && $pw !== '') {
            $env['MYSQL_PWD'] = $pw;
        }
        return $env;
    }

    /**
     * Pipe stdout of $producer through $filter, writing to $destPath.
     * Throws with the producer's stderr when either side fails.
     *
     * @param string[] $producer
     * @param string[] $filter
     */
    private function runPipeline(array $producer, array $filter, string $destPath, string $producerName): void
    {
        $out = @fopen($destPath, 'wb');
        if (!$out) throw new \RuntimeException("MysqlSnapshot: cannot open $destPath for writing");

        $descA = [
            0 => ['pipe', 'r'],   // mysqldump stdin (unused)
            1 => ['pipe', 'w'],   // mysqldump stdout → filter stdin
            2 => ['pipe', 'w'],   // mysqldump stderr
        ];
        $procA = proc_open($producer, $descA, $pipesA, null, $this->envBlock());
        if (!is_resource($procA)) { fclose($out); throw new \RuntimeException("MysqlSnapshot: cannot start $producerName"); }
        fclose($pipesA[0]);

        // Read producer's stdout into the filter (gzip)'s stdin.
        $descB = [
            0 => $pipesA[1],       // gzip stdin = producer stdout
            1 => $out,             // gzip stdout → file
            2 => ['pipe', 'w'],
        ];
        $procB = proc_open($filter, $descB, $pipesB, null, $this->envBlock());
        if (!is_resource($procB)) {
            proc_terminate($procA);
            proc_close($procA);
            fclose($out);
            throw new \RuntimeException('MysqlSnapshot: cannot start gzip');
        }

        $errA = stream_get_contents($pipesA[2]); fclose($pipesA[2]);
        $errB = stream_get_contents($pipesB[2]); fclose($pipesB[2]);

        $codeA = proc_close($procA);
        $codeB = proc_close($procB);
        fclose($out);

        if ($codeA !== 0) throw new \RuntimeException("MysqlSnapshot: $producerName failed (exit=$codeA): " . trim((string)$errA));
        if ($codeB !== 0) throw new \RuntimeException("MysqlSnapshot: gzip failed (exit=$codeB): " . trim((string)$errB));
    }

    /**
     * Pipe stdout of $producer (gunzip) into stdin of $consumer (mysql).
     *
     * @param string[] $producer
     * @param string[] $consumer
     */
    private function runRestore(array $producer, array $consumer): void
    {
        $descA = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $procA = proc_open($producer, $descA, $pipesA);
        if (!is_resource($procA)) throw new \RuntimeException('MysqlSnapshot: cannot start gunzip');
        fclose($pipesA[0]);

        $descB = [
            0 => $pipesA[1],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $procB = proc_open($consumer, $descB, $pipesB, null, $this->envBlock());
        if (!is_resource($procB)) {
            proc_terminate($procA);
            proc_close($procA);
            throw new \RuntimeException('MysqlSnapshot: cannot start mysql');
        }
        $errA = stream_get_contents($pipesA[2]); fclose($pipesA[2]);
        $errB = stream_get_contents($pipesB[2]); fclose($pipesB[2]);
        fclose($pipesB[1]);

        $codeA = proc_close($procA);
        $codeB = proc_close($procB);
        if ($codeA !== 0) throw new \RuntimeException("MysqlSnapshot: gunzip failed (exit=$codeA): " . trim((string)$errA));
        if ($codeB !== 0) throw new \RuntimeException("MysqlSnapshot: mysql restore failed (exit=$codeB): " . trim((string)$errB));
    }
}
