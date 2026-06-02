<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('short_links', function (Blueprint $t) {
        $t->id();
        $t->string('slug', 64)->unique();
        $t->text('target_url');
        $t->string('title')->nullable();
        $t->boolean('is_disabled')->default(false);
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->index(['created_by'])->name('idx_short_links_created_by');
    });

    $schema->createTableIfNotExists('short_link_visits', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('short_link_id');
        $t->string('ip_hash', 64)->nullable();
        $t->string('user_agent', 500)->nullable();
        $t->string('referer', 500)->nullable();
        $t->timestamp('created_at');
        $t->foreign('short_link_id')->references('id')->on('short_links')->onDelete('CASCADE');
        $t->index(['short_link_id', 'created_at'])->name('idx_short_link_visits_link_created');
        $t->index(['short_link_id', 'ip_hash'])->name('idx_short_link_visits_link_hash');
    });
};
