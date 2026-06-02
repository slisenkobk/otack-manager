<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

// For installations created before form_submissions had remote_ip.
// Fresh installs already have the column (added in the create migration);
// the ALTER is wrapped in try/catch so this is a portable no-op there.
return function (Schema $schema): void {
    try {
        $schema->alterTable('form_submissions', function (Blueprint $t) {
            $t->string('remote_ip', 45)->nullable();
        });
    } catch (\Throwable $_) {
        // Column already present — fresh install path.
    }
    try {
        $schema->execute(
            'CREATE INDEX idx_form_submissions_ip_time '
            . 'ON form_submissions(remote_ip, created_at)'
        );
    } catch (\Throwable $_) {
        // Index already present.
    }
};
