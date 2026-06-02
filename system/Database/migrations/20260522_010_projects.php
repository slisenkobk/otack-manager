<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('projects', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('slug', 100)->unique();
        $t->text('description')->nullable();
        $t->string('status', 20)->default('active');
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->foreign('created_by')->references('id')->on('users');
    });
};
