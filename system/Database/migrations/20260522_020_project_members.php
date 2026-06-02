<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('project_members', function (Blueprint $t) {
        $t->bigInteger('project_id');
        $t->bigInteger('user_id');
        $t->string('role', 20)->default('member');
        $t->primary(['project_id', 'user_id']);
        $t->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
        $t->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
    });
};
