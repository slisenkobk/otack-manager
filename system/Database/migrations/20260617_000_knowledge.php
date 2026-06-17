<?php
declare(strict_types=1);

// Knowledge base: flat-category Markdown pages + explicit append-only
// version snapshots. Tags + comments reuse the existing polymorphic
// scope-by-string design (TagRepository scope='knowledge_page',
// CommentRepository entity_type='knowledge_page'), so we don't need new
// tables for those.

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('knowledge_categories', function (Blueprint $t) {
        $t->id();
        $t->string('name', 120);
        // Used in URLs (/knowledge?category=<slug>) so admins can rename
        // a category without breaking links — slug stays put, name flows
        // through. Unique because /knowledge?category=foo expects one row.
        $t->string('slug', 140)->unique();
        $t->integer('sort_order')->default(0);
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->index(['sort_order'])->name('idx_knowledge_categories_sort');
    });

    $schema->createTable('knowledge_pages', function (Blueprint $t) {
        $t->id();
        // Same slug rationale as categories. Generated from title on
        // create; admin can edit it on the edit page.
        $t->string('slug', 200)->unique();
        $t->string('title', 240);
        // Source-of-truth Markdown. Rendered HTML is computed on every
        // show via Parsedown + HtmlSanitizer (cached at request scope by
        // the renderer if needed; for v1 we re-parse each load).
        $t->text('body_md')->nullable();
        // bigInteger (not integer) so the FK references line up on MySQL —
        // id() maps to BIGINT UNSIGNED, and MySQL 8 refuses cross-type FKs.
        // On SQLite both map to INTEGER affinity so there's no data drift.
        $t->bigInteger('category_id')->nullable();
        $t->bigInteger('author_id');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->foreign('category_id')->references('id')->on('knowledge_categories')->onDelete('SET NULL');
        $t->foreign('author_id')->references('id')->on('users')->onDelete('CASCADE');
        $t->index(['category_id'])->name('idx_knowledge_pages_category');
        $t->index(['author_id'])->name('idx_knowledge_pages_author');
        $t->index(['updated_at'])->name('idx_knowledge_pages_updated');
    });

    $schema->createTable('knowledge_page_versions', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('page_id');
        // Snapshot of title + body_md at the moment "Save snapshot" was
        // pressed. Append-only — versions are a read journal, never
        // restorable to head (per design discussion 2026-06-17).
        $t->string('title', 240);
        $t->text('body_md')->nullable();
        // Optional human-supplied label (e.g. "Pre-Q3 rewrite",
        // "Approved by Legal"). NULL is fine; renders as a generic
        // "Snapshot #N" in the journal.
        $t->string('note', 280)->nullable();
        $t->bigInteger('snapshot_by');
        $t->timestamp('snapshot_at');
        $t->foreign('page_id')->references('id')->on('knowledge_pages')->onDelete('CASCADE');
        $t->foreign('snapshot_by')->references('id')->on('users')->onDelete('CASCADE');
        $t->index(['page_id', 'snapshot_at'])->name('idx_knowledge_versions_page');
    });
};
