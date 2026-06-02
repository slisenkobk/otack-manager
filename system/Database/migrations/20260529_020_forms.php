<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('forms', function (Blueprint $t) {
        $t->id();
        $t->string('hash', 32)->unique();
        $t->string('title');
        $t->text('description')->nullable();
        $t->json('fields_json')->default('[]');
        $t->json('footer_json')->default('{}');
        $t->string('status', 20)->default('published');
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->index(['status'])->name('idx_forms_status');
    });
};
