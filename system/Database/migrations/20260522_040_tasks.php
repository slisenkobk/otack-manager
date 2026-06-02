<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('tasks', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('project_id');
        $t->bigInteger('column_id');
        $t->string('title');
        $t->text('description')->nullable();
        $t->real('position');
        $t->bigInteger('assignee_id')->nullable();
        $t->string('due_date', 32)->nullable();
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
        $t->foreign('column_id')->references('id')->on('task_columns');
        $t->foreign('assignee_id')->references('id')->on('users');
        $t->foreign('created_by')->references('id')->on('users');
    });
};
