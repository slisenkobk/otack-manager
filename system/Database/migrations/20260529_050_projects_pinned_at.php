<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('projects', function (Blueprint $t) {
        $t->timestamp('pinned_at')->nullable();
    });
};
