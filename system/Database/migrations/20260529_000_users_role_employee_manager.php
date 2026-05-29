<?php
declare(strict_types=1);

// Roles upgrade: existing 'member' rows mapped to 'employee'. New role
// 'manager' is introduced and accepted by UserController going forward.
return function (\PDO $pdo) {
    $pdo->exec("UPDATE users SET role = 'employee' WHERE role = 'member'");
};
