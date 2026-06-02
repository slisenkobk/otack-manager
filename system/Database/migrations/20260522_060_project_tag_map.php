<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('project_tag_map', function (Blueprint $t) {
        $t->bigInteger('project_id');
        $t->bigInteger('tag_id');
        $t->primary(['project_id', 'tag_id']);
        $t->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
        $t->foreign('tag_id')->references('id')->on('tags')->onDelete('CASCADE');
    });
};
