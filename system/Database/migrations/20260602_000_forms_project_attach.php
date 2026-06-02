<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('forms', function (Blueprint $t) {
        $t->bigInteger('project_id')->nullable();
        $t->boolean('auto_create_task')->default(false);
        $t->string('task_title_template', 500)->nullable();
    });
};
