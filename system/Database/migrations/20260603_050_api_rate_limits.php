<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    // Hot write path: every authenticated API call UPSERTs into this table. We
    // intentionally omit a FK on `token_id` — a revoked token returns 401 before
    // any rate-limit lookup, so a stale row here is harmless and saving the JOIN
    // check on every request matters. token_id is the PK (1:1 with api_tokens).
    $schema->createTableIfNotExists('api_rate_limits', function (Blueprint $t) {
        $t->bigInteger('token_id')->primary();
        $t->bigInteger('window_start');  // Unix epoch; sliding-window reset compares to time()
        $t->integer('count');
    });
};
