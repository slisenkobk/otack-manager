<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    // Mirrors api_rate_limits but keyed by sha256(lowercased email) instead of
    // a token id. Reused for any sliding-window throttle that doesn't fit the
    // api_tokens FK (login attempts, future per-IP throttles).
    $schema->createTableIfNotExists('login_attempts', function (Blueprint $t) {
        $t->string('key_hash', 64)->primary();
        $t->bigInteger('window_start');
        $t->integer('count');
    });
};
