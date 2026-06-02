<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('tags', function (Blueprint $t) {
        $t->id();
        $t->string('scope', 32);
        $t->string('name', 64);
        $t->string('color', 8)->default('#8B7C68');
        $t->unique(['scope', 'name']);
    });
};
