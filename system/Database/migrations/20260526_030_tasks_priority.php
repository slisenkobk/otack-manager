<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('tasks', function (Blueprint $t) {
        $t->string('priority', 16)->default('none');
    });
};
