<?php
declare(strict_types=1);

use App\Database\Schema\Schema;

// Roles upgrade: existing 'member' rows mapped to 'employee'. New role
// 'manager' is introduced and accepted by UserController going forward.
return function (Schema $schema): void {
    $schema->execute("UPDATE users SET role = 'employee' WHERE role = 'member'");
};
