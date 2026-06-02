<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTable('users', function (Blueprint $t) {
        $t->id();
        $t->string('email', 320)->unique();
        $t->string('password_hash', 255);
        $t->string('name', 200);
        $t->string('role', 20)->default('member');
        $t->string('status', 20)->default('pending');
        $t->string('telegram_chat_id', 64)->nullable();
        $t->timestamp('created_at');
        $t->timestamp('last_login_at')->nullable();
    });
};
