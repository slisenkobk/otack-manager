<?php
declare(strict_types=1);

use App\Database\Schema\Blueprint;
use App\Database\Schema\Schema;

return function (Schema $schema): void {
    $schema->createTableIfNotExists('polls', function (Blueprint $t) {
        $t->id();
        $t->string('hash', 32)->unique();
        $t->string('title');
        $t->text('description')->nullable();
        $t->json('fields_json')->default('[]');
        $t->json('footer_json')->default('{}');
        $t->string('contact_field', 16)->default('email');
        $t->string('locale', 8)->default('en');
        $t->text('success_message')->nullable();
        $t->string('status', 20)->default('draft');
        $t->bigInteger('project_id')->nullable();
        $t->timestamp('activated_at')->nullable();
        $t->timestamp('closed_at')->nullable();
        $t->bigInteger('summary_task_id')->nullable();
        $t->bigInteger('created_by');
        $t->timestamp('created_at');
        $t->timestamp('updated_at');
        $t->index(['status'])->name('idx_polls_status');
        $t->index(['project_id'])->name('idx_polls_project');
        $t->index(['created_by'])->name('idx_polls_created_by');
    });
};
