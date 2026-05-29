<?php
$statusLabels = [
  'new'               => 'New',
  'in_progress'       => 'In progress',
  'rejected'          => 'Rejected',
  'done'              => 'Done',
  'converted_task'    => 'Converted to task',
  'converted_project' => 'Converted to project',
];

// Render the filter selects as our custom-select component so they match the
// rest of the system styling.
$currentFormTitle = '';
if (!empty($currentForm)) {
    foreach ($forms as $f) { if ((int)$f['id'] === (int)$currentForm) { $currentFormTitle = $f['title']; break; } }
}
$currentStatusLabel = $statusLabels[$currentStatus] ?? '';
?>
<div class="page-actions" style="margin-bottom:var(--space-8);justify-content:flex-start;gap:10px;">
  <form method="get" action="/forms-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <div class="search-field search-field--with-submit" style="margin:0;">
      <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted);font-size:12px;"></i>
      <input class="input input--inline" name="q" placeholder="Search submissions…" value="<?= e($query ?? '') ?>">
      <button type="submit" class="search-field__submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
    </div>
    <div class="custom-select custom-select--compact" data-custom-select<?= count($forms) > 6 ? ' data-custom-select-search' : '' ?> style="width:200px;">
      <button type="button" class="custom-select__btn">
        <span class="custom-select__label"><?= e($currentFormTitle !== '' ? $currentFormTitle : 'All forms') ?></span>
        <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
      </button>
      <div class="custom-select__pop" hidden>
        <?php if (count($forms) > 6): ?>
          <div class="custom-select__search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="Search forms…" data-custom-select-search-input>
          </div>
        <?php endif; ?>
        <div class="custom-select__opts">
          <div class="custom-select__opt<?= $currentForm === null ? ' is-selected' : '' ?>" data-value="" data-search="all forms">
            <span class="custom-select__opt-label">All forms</span>
          </div>
          <?php foreach ($forms as $f): ?>
            <div class="custom-select__opt<?= (int)$currentForm === (int)$f['id'] ? ' is-selected' : '' ?>" data-value="<?= (int)$f['id'] ?>" data-search="<?= e(mb_strtolower($f['title'])) ?>">
              <span class="custom-select__opt-label"><?= e($f['title']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="custom-select__no-results" hidden>No matches.</div>
      </div>
      <input type="hidden" name="form_id" value="<?= e((string)($currentForm ?? '')) ?>" data-auto-submit-on-change>
    </div>

    <div class="custom-select custom-select--compact" data-custom-select style="width:200px;">
      <button type="button" class="custom-select__btn">
        <span class="custom-select__label"><?= e($currentStatusLabel !== '' ? $currentStatusLabel : 'All statuses') ?></span>
        <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
      </button>
      <div class="custom-select__pop" hidden>
        <div class="custom-select__opts">
          <div class="custom-select__opt<?= $currentStatus === '' ? ' is-selected' : '' ?>" data-value="">
            <span class="custom-select__opt-label">All statuses</span>
          </div>
          <?php foreach ($statusLabels as $sVal => $sLabel): ?>
            <div class="custom-select__opt<?= $currentStatus === $sVal ? ' is-selected' : '' ?>" data-value="<?= e($sVal) ?>">
              <span class="custom-select__opt-label"><?= e($sLabel) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <input type="hidden" name="status" value="<?= e((string)$currentStatus) ?>" data-auto-submit-on-change>
    </div>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="empty-state">
    <span class="empty-state__tag">Otack Manager</span>
    <p class="empty-state__text">No submissions yet.</p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($rows as $r):
      // First non-empty text-ish answer makes the card preview line
      $data = json_decode((string)$r['data_json'], true) ?: [];
      $preview = '';
      foreach ($data as $v) {
        if (is_array($v)) $v = implode(', ', $v);
        $v = trim((string)$v);
        if ($v !== '') { $preview = $v; break; }
      }
    ?>
      <a class="card" href="/forms-data/<?= (int)$r['id'] ?>" style="text-decoration:none;color:inherit;">
        <span class="corner-tag">S-<?= (int)$r['id'] ?></span>
        <span class="corner-meta"><?= fmt_datetime($r['created_at']) ?></span>
        <div class="card-head">
          <div class="ini" style="background: var(--ink-2);"><i class="fa-solid fa-envelope-open-text" style="color:var(--paper);font-size:14px;"></i></div>
          <div style="flex:1;min-width:0;">
            <h3 class="name"><?= e($r['form_title']) ?></h3>
          </div>
        </div>
        <?php if ($preview !== ''): ?>
          <p class="card__desc"><?= e(mb_strimwidth($preview, 0, 140, '…')) ?></p>
        <?php endif; ?>
        <div class="card-row" style="margin-top:auto;">
          <span class="status status--<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span>
          <span class="share-link">Open <span class="arr">&#8594;</span></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/forms-data-index.js"></script>
