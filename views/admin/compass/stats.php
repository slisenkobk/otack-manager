<?php
// Inputs: $db {tables:[{name,rows}], db_path, db_size, last_migration}
$totalRows = 0;
foreach ($db['tables'] as $t) if ($t['rows'] >= 0) $totalRows += $t['rows'];
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div class="split-head">
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
      <div class="text-right">
        <p class="muted compass-last-migration-label"><?= e(t('compass.db.last_migration')) ?></p>
        <code class="fz-12"><?= e($db['last_migration']['name']) ?></code>
        <p class="muted compass-last-migration-time"><?= e(t('compass.db.last_migration_at', ['time' => $db['last_migration']['applied_at']])) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <table class="data-table">
    <thead>
      <tr class="table-head-row">
        <th class="table-cell--strong"><?= e(t('compass.db.col.table')) ?></th>
        <th class="table-cell--strong-right"><?= e(t('compass.db.col.rows')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($db['tables'] as $tb): ?>
      <tr class="border-rule-bottom">
        <td><code class="fz-12 table-cell"><?= e($tb['name']) ?></code></td>
        <td class="table-cell--num-right">
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
