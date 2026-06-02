<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->alterTable('forms', function (Blueprint $t) {
        $t->text('success_message')->nullable();
    });
};
