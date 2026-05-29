<?php
$statusLabels = [
  'new'               => 'New',
  'in_progress'       => 'In progress',
  'rejected'          => 'Rejected',
  'done'              => 'Done',
  'converted_task'    => 'Converted to task',
  'converted_project' => 'Converted to project',
];
?>
<div class="page-actions" style="margin-bottom:var(--space-8);">
  <form method="get" action="/forms-data" style="display:flex;gap:10px;align-items:center;margin-left:auto;">
    <select name="form_id" class="select select--inline" style="font-size:12px;padding:7px 28px 7px 12px;width:auto;min-width:160px;" data-auto-submit-on-change>
      <option value="">All forms</option>
      <?php foreach ($forms as $f): ?>
        <option value="<?= (int)$f['id'] ?>"<?= (int)$currentForm === (int)$f['id'] ? ' selected' : '' ?>><?= e($f['title']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="select select--inline" style="font-size:12px;padding:7px 28px 7px 12px;width:auto;min-width:160px;" data-auto-submit-on-change>
      <option value="">All statuses</option>
      <?php foreach ($statusLabels as $sVal => $sLabel): ?>
        <option value="<?= e($sVal) ?>"<?= $currentStatus === $sVal ? ' selected' : '' ?>><?= e($sLabel) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="empty-state">
    <span class="empty-state__tag">Otack Manager</span>
    <p class="empty-state__text">No submissions yet.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:8px;">
    <?php foreach ($rows as $r): ?>
      <a class="card" href="/forms-data/<?= (int)$r['id'] ?>" style="text-decoration:none;color:inherit;flex-direction:row;align-items:center;gap:18px;min-height:auto;">
        <span class="corner-tag">S-<?= (int)$r['id'] ?></span>
        <span class="corner-meta"><?= fmt_datetime($r['created_at']) ?></span>
        <div style="flex:1;min-width:0;padding-top:14px;">
          <div style="font-weight:600;font-size:14px;"><?= e($r['form_title']) ?></div>
          <div style="font-family:var(--font-mono);font-size:11px;color:var(--ink-3);margin-top:4px;">submission #<?= (int)$r['id'] ?></div>
        </div>
        <span class="status status--<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/forms-data-index.js"></script>
