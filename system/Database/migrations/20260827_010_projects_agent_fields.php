<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// Ties a project to the code it produces, for the external agent bridge.
// The *local filesystem path* is deliberately NOT stored here: it lives in
// the operator's machine-local config, so editing a field in the web UI can
// never redirect an automated worker at an arbitrary directory.
return function (Schema $schema): void {
    $schema->alterTable('projects', function (Blueprint $t) {
        $t->string('repo_url', 500)->nullable();
        $t->string('default_branch', 100)->nullable();
        $t->string('dev_branch', 100)->nullable();
        $t->string('dev_url', 500)->nullable();
        $t->text('agent_instructions')->nullable();
    });
};
