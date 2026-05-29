<?php
// Inputs: $sessions{total,stale,stale_bytes,lifetime_seconds},
//         $uploads{total,orphan,orphan_bytes},
//         $assetVersion, $csrfToken
$hours = (int)round($sessions['lifetime_seconds'] / 3600);
$card = 'background:var(--paper);border:1px solid var(--rule);border-radius:var(--radius);padding:var(--space-8);display:flex;flex-direction:column;gap:var(--space-4);';
$cardTitle = 'font-size:15px;font-weight:600;margin:0;display:flex;align-items:center;gap:var(--space-3);';
?>
<div style="max-width:960px;margin:0 auto;">
  <?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--space-6);">

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-clock-rotate-left" style="color:var(--text-2);" aria-hidden="true"></i>
        Sessions
      </h3>
      <p style="margin:0;font-size:14px;">
        <strong><?= (int)$sessions['total'] ?></strong> total ·
        <strong style="color:<?= $sessions['stale'] > 0 ? 'var(--accent)' : 'var(--text-2)' ?>;">
          <?= (int)$sessions['stale'] ?>
        </strong> stale
        <span class="muted">(<?= e(human_bytes((int)$sessions['stale_bytes'])) ?>)</span>
      </p>
      <p class="muted" style="font-size:12px;margin:0;">Stale = older than <?= $hours ?>h (SESSION_LIFETIME). Active sessions are kept.</p>
      <div>
        <button class="btn btn--danger" type="button"
                data-compass-action="clear-sessions"
                data-compass-count="<?= (int)$sessions['stale'] ?>"
                <?= $sessions['stale'] === 0 ? 'disabled' : '' ?>>
          Clear stale sessions
        </button>
      </div>
    </section>

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-folder-open" style="color:var(--text-2);" aria-hidden="true"></i>
        Orphan uploads
      </h3>
      <p style="margin:0;font-size:14px;">
        <strong><?= (int)$uploads['total'] ?></strong> files on disk ·
        <strong style="color:<?= $uploads['orphan'] > 0 ? 'var(--accent)' : 'var(--text-2)' ?>;">
          <?= (int)$uploads['orphan'] ?>
        </strong> orphan
        <span class="muted">(<?= e(human_bytes((int)$uploads['orphan_bytes'])) ?>)</span>
      </p>
      <p class="muted" style="font-size:12px;margin:0;">
        Files present in <code>public/uploads/</code> but missing from the <code>attachments</code> table.
      </p>
      <div>
        <button class="btn btn--danger" type="button"
                data-compass-action="clear-uploads-orphans"
                data-compass-count="<?= (int)$uploads['orphan'] ?>"
                <?= $uploads['orphan'] === 0 ? 'disabled' : '' ?>>
          Delete orphan files
        </button>
      </div>
    </section>

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-rotate" style="color:var(--text-2);" aria-hidden="true"></i>
        Asset cache
      </h3>
      <p style="margin:0;font-size:14px;">
        Current version:
        <strong>
          <?php if ($assetVersion !== ''): ?>
            <code><?= e($assetVersion) ?></code>
          <?php else: ?>
            <span class="muted">none</span>
          <?php endif; ?>
        </strong>
      </p>
      <p class="muted" style="font-size:12px;margin:0;">
        Bumps the <code>?v=</code> query on every CSS/JS link so clients refetch.
      </p>
      <div>
        <button class="btn btn--primary" type="button" data-compass-action="bust-asset-cache">
          Bust browser cache
        </button>
      </div>
    </section>

  </div>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
