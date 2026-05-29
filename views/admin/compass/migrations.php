<?php
// Inputs: $status (list of {name, applied}), $appliedAt (name => applied_at),
// $pendingCount (int), $csrfToken
$total = count($status);
?>
<div style="max-width:960px;margin:0 auto;">
  <?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--space-8);margin-bottom:var(--space-6);flex-wrap:wrap;">
    <div>
      <h2 style="font-size:20px;font-weight:600;margin:0 0 var(--space-2);">Schema migrations</h2>
      <p class="muted" style="font-size:13px;margin:0;">
        <?php if ($pendingCount > 0): ?>
          <span style="color:var(--accent);font-weight:600;"><?= (int)$pendingCount ?> pending</span> ·
        <?php else: ?>
          <span style="color:var(--text-2);font-weight:600;">All applied</span> ·
        <?php endif; ?>
        <?= $total ?> total file<?= $total === 1 ? '' : 's' ?>
      </p>
    </div>
    <button class="btn btn--primary" type="button"
            data-compass-run-migrations
            <?= $pendingCount === 0 ? 'disabled' : '' ?>>
      <i class="fa-solid fa-play" aria-hidden="true" style="margin-right:var(--space-2);"></i>
      Run pending
    </button>
  </div>

  <table style="width:100%;border-collapse:collapse;font-size:13px;">
    <thead>
      <tr style="text-align:left;border-bottom:1px solid var(--rule);">
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);">Migration</th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);">Applied at</th>
        <th style="padding:var(--space-3) var(--space-4);font-weight:600;color:var(--text-2);text-align:right;">Status</th>
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
            <span style="color:var(--text-2);font-size:11px;text-transform:uppercase;letter-spacing:.05em;">applied</span>
          <?php else: ?>
            <span style="color:var(--accent);font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;">pending</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
