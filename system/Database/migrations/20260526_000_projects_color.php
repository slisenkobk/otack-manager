<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('projects', function (Blueprint $t) {
        $t->string('color', 8)->default('#1A1612');
    });
};
