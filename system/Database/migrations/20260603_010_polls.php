<?php
declare(strict_types=1);

// Polls: form-shaped questionnaires with a contact-gate and per-contact dedup.
//   fields_json     — same schema the form-builder emits; first two fields are
//                     pinned by the builder (contact + a radio "choices").
//   contact_field   — 'email' | 'phone'; drives validation + normalization at vote time.
//   status          — draft → active → closed (one-way).
//   activated_at /
//   closed_at       — timestamps for the two transitions (nullable until they happen).
//   project_id      — attach to a project for the post-close "create summary task".
//   summary_task_id — set once a summary task has been created, so the UI can stop offering.
return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS polls (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            hash            TEXT NOT NULL UNIQUE,
            title           TEXT NOT NULL,
            description     TEXT NULL,
            fields_json     TEXT NOT NULL DEFAULT "[]",
            footer_json     TEXT NOT NULL DEFAULT "{}",
            contact_field   TEXT NOT NULL DEFAULT "email",
            locale          TEXT NOT NULL DEFAULT "en",
            success_message TEXT NULL,
            status          TEXT NOT NULL DEFAULT "draft",
            project_id      INTEGER NULL,
            activated_at    TEXT NULL,
            closed_at       TEXT NULL,
            summary_task_id INTEGER NULL,
            created_by      INTEGER NOT NULL,
            created_at      TEXT NOT NULL,
            updated_at      TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_polls_status     ON polls(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_polls_project    ON polls(project_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_polls_created_by ON polls(created_by)');
};
