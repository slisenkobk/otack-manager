<?php
declare(strict_types=1);

// Pivot for knowledge_page ↔ tag. Tags themselves stay in the existing
// `tags` table (scope='knowledge_page'). We follow the same per-entity
// pivot pattern as project_tag_map / task_tag_map rather than the
// polymorphic shape comments uses, so listFor… queries stay one JOIN
// deep and indexes are simple.

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('knowledge_page_tag_map', function (Blueprint $t) {
        $t->id();
        $t->integer('page_id');
        $t->integer('tag_id');
        $t->foreign('page_id')->references('id')->on('knowledge_pages')->onDelete('CASCADE');
        $t->foreign('tag_id')->references('id')->on('tags')->onDelete('CASCADE');
        $t->index(['page_id'])->name('idx_kpt_page');
        $t->index(['tag_id'])->name('idx_kpt_tag');
        $t->unique(['page_id', 'tag_id'])->name('uniq_kpt_page_tag');
    });
};
