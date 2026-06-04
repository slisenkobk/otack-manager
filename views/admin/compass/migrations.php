<?php
// Inputs: $status (list of {name, applied}), $appliedAt (name => applied_at),
// $pendingCount (int), $csrfToken
$total = count($status);
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div class="split-head">
    <div>
      <h2 class="fz-20 fw-600 u-mb-space-2"><?= e(t('compass.migrations.heading')) ?></h2>
      <p class="muted fz-13 m-0">
        <?php if ($pendingCount > 0): ?>
          <span class="compass-meta-strong--accent"><?= e(t('compass.migrations.pending', ['n' => (int)$pendingCount])) ?></span> ·
        <?php else: ?>
          <span class="compass-meta-strong"><?= e(t('compass.migrations.all_applied')) ?></span> ·
        <?php endif; ?>
        <?= e(tn('file.count', $total, ['n' => $total])) ?>
      </p>
    </div>
    <button class="btn btn--primary" type="button"
            data-compass-run-migrations
            data-compass-confirm="<?= e(t('compass.migrations.run_confirm')) ?>"
            data-compass-confirm-label="<?= e(t('compass.migrations.run_button')) ?>"
            <?= $pendingCount === 0 ? 'disabled' : '' ?>>
      <i class="fa-solid fa-play mr-space-2" aria-hidden="true"></i>
      <?= e(t('compass.migrations.run_pending')) ?>
    </button>
  </div>

  <table class="data-table">
    <thead>
      <tr class="table-head-row">
        <th class="table-cell--strong"><?= e(t('compass.migrations.col.migration')) ?></th>
        <th class="table-cell--strong"><?= e(t('compass.migrations.col.applied_at')) ?></th>
        <th class="table-cell--strong-right"><?= e(t('compass.migrations.col.status')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($status as $row): ?>
      <tr class="border-rule-bottom">
        <td><code class="fz-12 table-cell"><?= e($row['name']) ?></code></td>
        <td class="muted table-cell">
          <?= $row['applied'] && isset($appliedAt[$row['name']]) ? e($appliedAt[$row['name']]) : '—' ?>
        </td>
        <td class="table-cell text-right">
          <?php if ($row['applied']): ?>
            <span class="compass-status-badge"><?= e(t('compass.migrations.applied_badge')) ?></span>
          <?php else: ?>
            <span class="compass-status-badge compass-status-badge--accent"><?= e(t('compass.migrations.pending_badge')) ?></span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
