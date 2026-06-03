<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    // Timestamps stored as Unix epoch (BIGINT), not ISO strings as the rest of the
    // schema does. Tokens compare `expires_at > time()` arithmetically and the API
    // layer formats them to ISO-8601 on output, so epoch ints are simpler here than
    // parsing ISO strings on every auth check.
    $schema->createTableIfNotExists('api_tokens', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('user_id');
        $t->string('name');
        $t->string('token_hash', 64)->unique();  // SHA-256 hex; never store the plaintext token
        $t->string('prefix', 16);
        $t->bigInteger('last_used_at')->nullable();
        $t->string('last_used_ip', 64)->nullable();
        $t->bigInteger('created_at');
        $t->bigInteger('expires_at')->nullable();
        $t->bigInteger('revoked_at')->nullable();
        $t->index(['user_id', 'revoked_at'])->name('idx_api_tokens_user_active');
        $t->index(['token_hash'])->name('idx_api_tokens_hash');
        $t->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
    });
};
