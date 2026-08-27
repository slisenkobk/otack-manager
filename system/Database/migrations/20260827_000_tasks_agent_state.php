<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// Agent execution phase, owned by the external agent bridge. Deliberately
// NOT reusing `sub_status`: that column belongs to the app (reopened /
// returned) and TaskRepository::move() clears it, which would wipe the
// agent's phase exactly when the agent moves the card.
return function (Schema $schema): void {
    $schema->alterTable('tasks', function (Blueprint $t) {
        $t->string('agent_state', 32)->nullable();
        $t->timestamp('agent_state_at')->nullable();
    });
};
