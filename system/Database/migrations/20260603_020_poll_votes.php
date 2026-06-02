<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('poll_votes', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('poll_id');
        $t->string('contact', 320);
        $t->string('contact_normalized', 320);
        $t->string('choice_key', 64);
        $t->string('remote_ip', 45)->nullable();
        $t->string('user_agent', 500)->nullable();
        $t->timestamp('created_at');
        $t->foreign('poll_id')->references('id')->on('polls')->onDelete('CASCADE');
        $t->unique(['poll_id', 'contact_normalized'])->name('idx_poll_votes_unique');
        $t->index(['poll_id', 'choice_key'])->name('idx_poll_votes_choice');
        $t->index(['poll_id', 'created_at'])->name('idx_poll_votes_created');
    });
};
