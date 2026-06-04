<?php
// Inputs: $db {tables:[{name,rows}], db_path, db_size, last_migration}
$totalRows = 0;
foreach ($db['tables'] as $t) if ($t['rows'] >= 0) $totalRows += $t['rows'];
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--space-8);margin-bottom:var(--space-6);flex-wrap:wrap;">
    <div>
      <h2 class="fz-20 fw-600 u-mb-space-2"><?= e(t('compass.db.heading')) ?></h2>
      <p class="muted fz-13 m-0">
        <?= t('compass.db.meta', [
            'path'   => '<code>' . e(basename($db['db_path'])) . '</code>',
            'size'   => e(human_bytes((int)$db['db_size'])),
            'tables' => count($db['tables']),
            'rows'   => number_format($totalRows),
        ]) ?>
      </p>
    </div>
    <?php if ($db['last_migration']): ?>
      <div style="text-align:right;">
        <p class="muted" style="font-size:11px;margin:0 0 2px;text-transform:uppercase;letter-spacing:.05em;"><?= e(t('compass.db.last_migration')) ?></p>
        <code class="fz-12"><?= e($db['last_migration']['name']) ?></code>
        <p class="muted" style="font-size:12px;margin:2px 0 0;"><?= e(t('compass.db.last_migration_at', ['time' => $db['last_migration']['applied_at']])) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid var(--rule);">
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);"><?= e(t('compass.db.col.table')) ?></th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);text-align:right;"><?= e(t('compass.db.col.rows')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($db['tables'] as $tb): ?>
      <tr style="border-bottom:1px solid var(--rule);">
        <td style="padding:var(--space-3) var(--space-4);"><code class="fz-12"><?= e($tb['name']) ?></code></td>
        <td style="padding:var(--space-3) var(--space-4);text-align:right;font-variant-numeric:tabular-nums;">
          <?php if ($tb['rows'] < 0): ?>
            <span class="muted"><?= e(t('compass.db.error')) ?></span>
          <?php else: ?>
            <?= number_format((int)$tb['rows']) ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
