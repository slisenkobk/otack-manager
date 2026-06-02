<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('comments', function (Blueprint $t) {
        $t->bigInteger('parent_id')->nullable();
        $t->index(['parent_id'])->name('idx_comments_parent');
    });
};
