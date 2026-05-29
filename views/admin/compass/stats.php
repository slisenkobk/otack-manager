<?php
// Inputs: $db {tables:[{name,rows}], db_path, db_size, last_migration}
$hb = function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KB', 'MB', 'GB'];
    $v = $bytes / 1024; $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return number_format($v, $v >= 100 ? 0 : 1) . ' ' . $units[$i];
};
$totalRows = 0;
foreach ($db['tables'] as $t) if ($t['rows'] >= 0) $totalRows += $t['rows'];
?>
<div style="max-width:960px;margin:0 auto;">
  <?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--space-8);margin-bottom:var(--space-6);flex-wrap:wrap;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0 0 var(--space-2);">Database</h2>
      <p class="muted" style="font-size:13px;margin:0;">
        <code><?= e(basename($db['db_path'])) ?></code> ·
        <?= e($hb((int)$db['db_size'])) ?> ·
        <?= count($db['tables']) ?> tables ·
        <?= number_format($totalRows) ?> total rows
      </p>
    </div>
    <?php if ($db['last_migration']): ?>
      <div style="text-align:right;">
        <p class="muted" style="font-size:11px;margin:0 0 2px;text-transform:uppercase;letter-spacing:.05em;">Last migration</p>
        <code style="font-size:12px;"><?= e($db['last_migration']['name']) ?></code>
        <p class="muted" style="font-size:12px;margin:2px 0 0;">at <?= e($db['last_migration']['applied_at']) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid var(--rule);">
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);">Table</th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);text-align:right;">Rows</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($db['tables'] as $t): ?>
      <tr style="border-bottom:1px solid var(--rule);">
        <td style="padding:var(--space-3) var(--space-4);"><code style="font-size:12px;"><?= e($t['name']) ?></code></td>
        <td style="padding:var(--space-3) var(--space-4);text-align:right;font-variant-numeric:tabular-nums;">
          <?php if ($t['rows'] < 0): ?>
            <span class="muted">err</span>
          <?php else: ?>
            <?= number_format((int)$t['rows']) ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
