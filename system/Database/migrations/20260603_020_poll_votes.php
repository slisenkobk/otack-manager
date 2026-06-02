<?php
declare(strict_types=1);

// One row per accepted vote.
//   contact            — what the voter typed (kept for display in the Voters tab).
//   contact_normalized — lower-trimmed email or digits-only phone; UNIQUE per poll
//                        is what enforces one-vote-per-contact (hard dedup).
//   choice_key         — stable identifier of the chosen radio option, NOT its label,
//                        so renaming labels (in draft) wouldn't break historical tallies.
return function (\PDO $pdo) {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS poll_votes (
            id                 INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id            INTEGER NOT NULL,
            contact            TEXT NOT NULL,
            contact_normalized TEXT NOT NULL,
            choice_key         TEXT NOT NULL,
            remote_ip          TEXT NULL,
            user_agent         TEXT NULL,
            created_at         TEXT NOT NULL,
            FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_poll_votes_unique  ON poll_votes(poll_id, contact_normalized)');
    $pdo->exec('CREATE INDEX        IF NOT EXISTS idx_poll_votes_choice  ON poll_votes(poll_id, choice_key)');
    $pdo->exec('CREATE INDEX        IF NOT EXISTS idx_poll_votes_created ON poll_votes(poll_id, created_at)');
};
