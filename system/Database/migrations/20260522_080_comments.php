<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('comments', function (Blueprint $t) {
        $t->id();
        $t->string('entity_type', 32);
        $t->bigInteger('entity_id');
        $t->bigInteger('user_id');
        $t->text('body');
        $t->timestamp('created_at');
        $t->index(['entity_type', 'entity_id', 'created_at'])->name('comments_entity_idx');
        $t->foreign('user_id')->references('id')->on('users');
    });
};
