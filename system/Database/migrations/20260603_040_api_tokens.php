<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('api_tokens', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('user_id');
        $t->string('name');
        $t->string('token_hash', 64)->unique();
        $t->string('prefix', 16);
        $t->bigInteger('last_used_at')->nullable();
        $t->string('last_used_ip', 64)->nullable();
        $t->bigInteger('created_at');
        $t->bigInteger('expires_at')->nullable();
        $t->bigInteger('revoked_at')->nullable();
        $t->index(['user_id', 'revoked_at'])->name('idx_api_tokens_user_active');
        $t->index(['token_hash'])->name('idx_api_tokens_hash');
        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    });
};
