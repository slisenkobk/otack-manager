<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('attachments', function (Blueprint $t) {
        $t->id();
        $t->string('entity_type', 32);
        $t->bigInteger('entity_id');
        $t->string('filename');
        $t->string('original_name');
        $t->string('mime', 128);
        $t->bigInteger('size');
        $t->boolean('is_image')->default(false);
        $t->bigInteger('uploaded_by');
        $t->timestamp('created_at');
        $t->index(['entity_type', 'entity_id'])->name('attachments_entity_idx');
        $t->foreign('uploaded_by')->references('id')->on('users');
    });
};
