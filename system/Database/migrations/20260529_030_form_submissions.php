<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('form_submissions', function (Blueprint $t) {
        $t->id();
        $t->bigInteger('form_id');
        $t->json('data_json')->default('{}');
        $t->json('footer_json')->default('{}');
        $t->string('status', 20)->default('new');
        $t->bigInteger('converted_task_id')->nullable();
        $t->bigInteger('converted_project_id')->nullable();
        $t->string('remote_ip', 45)->nullable();
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->index(['form_id'])->name('idx_submissions_form');
        $t->index(['status'])->name('idx_submissions_status');
        $t->index(['remote_ip', 'created_at'])->name('idx_form_submissions_ip_time');
    });
};
