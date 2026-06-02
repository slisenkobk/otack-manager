<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('app_versions', function (Blueprint $t) {
        $t->id();
        $t->string('version', 32);
        $t->timestamp('installed_at');
        $t->string('source', 16);
        $t->bigInteger('applied_by')->nullable();
        $t->text('notes')->nullable();
        $t->index(['installed_at'])->name('idx_app_versions_installed_at');
    });

    $schema->createTableIfNotExists('app_backups', function (Blueprint $t) {
        $t->id();
        $t->string('version_from', 32);
        $t->string('version_to', 32);
        $t->timestamp('created_at');
        $t->string('code_path', 500);
        $t->string('db_snapshot', 500)->nullable();
        $t->bigInteger('size_bytes')->default(0);
        $t->string('kind', 16)->default('auto');
        $t->timestamp('pruned_at')->nullable();
        $t->index(['created_at'])->name('idx_app_backups_created_at');
    });

    // Seed the very first app_versions row so the History panel never
    // shows an empty install lineage.
    $pdo = $schema->pdo();
    $existing = (int)$pdo->query('SELECT COUNT(*) FROM app_versions')->fetchColumn();
    if ($existing === 0) {
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.u\Z');
        $version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        $pdo->prepare(
            "INSERT INTO app_versions (version, installed_at, source, applied_by, notes)
             VALUES (?, ?, 'install', NULL, NULL)"
        )->execute([$version, $now]);
    }
};
