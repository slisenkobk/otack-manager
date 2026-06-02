<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// Adds per-user locale preference (en / pl). Default 'en' so existing rows
// match the new default behaviour.
return function (Schema $schema): void {
    $schema->alterTable('users', function (Blueprint $t) {
        $t->string('locale', 8)->default('en');
    });
};
