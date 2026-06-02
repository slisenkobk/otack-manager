<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('task_columns', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('project_id');
        $t->string('name');
        $t->string('color', 8)->default('#8B7C68');
        $t->integer('position');
        $t->boolean('is_done')->default(false);
        $t->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
    });
};
