<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('task_tag_map', function (Blueprint $t) {
        $t->bigInteger('task_id');
        $t->bigInteger('tag_id');
        $t->primary(['task_id', 'tag_id']);
        $t->foreign('task_id')->references('id')->on('tasks')->onDelete('CASCADE');
        $t->foreign('tag_id')->references('id')->on('tags')->onDelete('CASCADE');
    });
};
