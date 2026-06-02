<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('notifications_log', function (Blueprint $t) {
        $t->id();
        $t->string('event', 64);
        $t->text('payload');
        $t->boolean('ok');
        $t->text('error')->nullable();
        $t->timestamp('sent_at');
    });
};
