<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('task_links', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('task_id');
        $t->bigInteger('linked_task_id');
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->unique(['task_id', 'linked_task_id']);
        $t->index(['task_id'])->name('idx_task_links_task');
        $t->index(['linked_task_id'])->name('idx_task_links_linked');
    });
};
