<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('settings', function (Blueprint $t) {
        $t->string('key', 191)->primary();
        $t->text('value')->default('');
    });
};
