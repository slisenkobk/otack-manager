<?php
declare(strict_types=1);
namespace App\Database;

final class SchemaBootstrap
{
    public function __construct(private \PDO $pdo, private string $markerDir)
    {
        if (!is_dir($markerDir)) mkdir($markerDir, 0755, true);
    }

    public function ensure(string $key, int $version, callable $migrate): void
    {
        $marker = $this->markerDir . "/$key.$version";
        if (is_file($marker)) return;
        $migrate($this->pdo);
        file_put_contents($marker, (string)time());
    }
}
