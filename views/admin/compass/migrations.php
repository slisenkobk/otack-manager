<?php
// Inputs: $status (list of {name, applied}), $appliedAt (name => applied_at),
// $pendingCount (int), $csrfToken
$total = count($status);
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--space-8);margin-bottom:var(--space-6);flex-wrap:wrap;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0 0 var(--space-2);"><?= e(t('compass.migrations.heading')) ?></h2>
      <p class="muted" style="font-size:13px;margin:0;">
        <?php if ($pendingCount > 0): ?>
          <span style="color:var(--accent);font-weight:600;"><?= e(t('compass.migrations.pending', ['n' => (int)$pendingCount])) ?></span> ·
        <?php else: ?>
          <span style="color:var(--text-2);font-weight:600;"><?= e(t('compass.migrations.all_applied')) ?></span> ·
        <?php endif; ?>
        <?= e(tn('file.count', $total, ['n' => $total])) ?>
      </p>
    </div>
    <button class="btn btn--primary" type="button"
            data-compass-run-migrations
            data-compass-confirm="<?= e(t('compass.migrations.run_confirm')) ?>"
            data-compass-confirm-label="<?= e(t('compass.migrations.run_button')) ?>"
            <?= $pendingCount === 0 ? 'disabled' : '' ?>>
      <i class="fa-solid fa-play" aria-hidden="true" style="margin-right:var(--space-2);"></i>
      <?= e(t('compass.migrations.run_pending')) ?>
    </button>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid var(--rule);">
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);"><?= e(t('compass.migrations.col.migration')) ?></th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);"><?= e(t('compass.migrations.col.applied_at')) ?></th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);text-align:right;"><?= e(t('compass.migrations.col.status')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($status as $row): ?>
      <tr style="border-bottom:1px solid var(--rule);">
        <td style="padding:var(--space-3) var(--space-4);"><code style="font-size:12px;"><?= e($row['name']) ?></code></td>
        <td style="padding:var(--space-3) var(--space-4);" class="muted">
          <?= $row['applied'] && isset($appliedAt[$row['name']]) ? e($appliedAt[$row['name']]) : '—' ?>
        </td>
        <td style="padding:var(--space-3) var(--space-4);text-align:right;">
          <?php if ($row['applied']): ?>
            <span style="color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.05em;"><?= e(t('compass.migrations.applied_badge')) ?></span>
          <?php else: ?>
            <span style="color:var(--accent);font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;"><?= e(t('compass.migrations.pending_badge')) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
