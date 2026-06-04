<?php
$statusLabels = [
  'new'               => t('status.submission.new'),
  'in_progress'       => t('status.submission.in_progress'),
  'rejected'          => t('status.submission.rejected'),
  'done'              => t('status.submission.done'),
  'converted_task'    => t('status.submission.converted_task'),
  'converted_project' => t('status.submission.converted_project'),
];

$currentFormTitle = '';
if (!empty($currentForm)) {
    foreach ($forms as $f) { if ((int)$f['id'] === (int)$currentForm) { $currentFormTitle = $f['title']; break; } }
}
$currentStatusLabel = $statusLabels[$currentStatus] ?? '';
?>
<div class="page-actions page-actions--filters">
  <form method="get" action="/forms-data" class="forms-data-filters">
    <div class="search-field search-field--with-submit m-0">
      <i class="fa-solid fa-magnifying-glass muted fz-12"></i>
      <input class="input input--inline" name="q" placeholder="<?= e(t('forms_data.search_placeholder')) ?>" value="<?= e($query ?? '') ?>">
      <button type="submit" class="search-field__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
    </div>
    <div class="forms-data-filters__selects">
    <div class="custom-select custom-select--compact w-200" data-custom-select<?= count($forms) > 6 ? ' data-custom-select-search' : '' ?>>
      <button type="button" class="custom-select__btn">
        <span class="custom-select__label"><?= e($currentFormTitle !== '' ? $currentFormTitle : t('forms_data.all_forms')) ?></span>
        <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
      </button>
      <div class="custom-select__pop" hidden>
        <?php if (count($forms) > 6): ?>
          <div class="custom-select__search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" placeholder="<?= e(t('forms.search_placeholder')) ?>" data-custom-select-search-input>
          </div>
        <?php endif; ?>
        <div class="custom-select__opts">
          <div class="custom-select__opt<?= $currentForm === null ? ' is-selected' : '' ?>" data-value="" data-search="all forms">
            <span class="custom-select__opt-label"><?= e(t('forms_data.all_forms')) ?></span>
          </div>
          <?php foreach ($forms as $f): ?>
            <div class="custom-select__opt<?= (int)$currentForm === (int)$f['id'] ? ' is-selected' : '' ?>" data-value="<?= (int)$f['id'] ?>" data-search="<?= e(mb_strtolower($f['title'])) ?>">
              <span class="custom-select__opt-label"><?= e($f['title']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="custom-select__no-results" hidden><?= e(t('common.no_results')) ?></div>
      </div>
      <input type="hidden" name="form_id" value="<?= e((string)($currentForm ?? '')) ?>" data-auto-submit-on-change>
    </div>

    <div class="custom-select custom-select--compact w-200" data-custom-select>
      <button type="button" class="custom-select__btn">
        <span class="custom-select__label"><?= e($currentStatusLabel !== '' ? $currentStatusLabel : t('forms_data.all_statuses')) ?></span>
        <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
      </button>
      <div class="custom-select__pop" hidden>
        <div class="custom-select__opts">
          <div class="custom-select__opt<?= $currentStatus === '' ? ' is-selected' : '' ?>" data-value="">
            <span class="custom-select__opt-label"><?= e(t('forms_data.all_statuses')) ?></span>
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
    </div>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= e(t('forms_data.empty')) ?></p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($rows as $r):
      $data = json_decode((string)$r['data_json'], true) ?: [];
      $preview = '';
      foreach ($data as $v) {
        if (is_array($v)) $v = implode(', ', $v);
        $v = trim((string)$v);
        if ($v !== '') { $preview = $v; break; }
      }
    ?>
      <a class="card no-underline-inherit" href="/forms-data/<?= (int)$r['id'] ?>">
        <span class="corner-tag">S-<?= (int)$r['id'] ?></span>
        <span class="corner-meta"><?= fmt_datetime($r['created_at']) ?></span>
        <div class="card-head">
          <div class="ini ini--ink-2"><i class="fa-solid fa-envelope-open-text text-paper fz-14"></i></div>
          <div class="flex-1-min">
            <h3 class="name"><?= e($r['form_title']) ?></h3>
          </div>
        </div>
        <?php if ($preview !== ''): ?>
          <p class="card__desc"><?= e(mb_strimwidth($preview, 0, 140, '…')) ?></p>
        <?php endif; ?>
        <div class="card-row card-row--push-bottom">
          <span class="status status--<?= e($r['status']) ?>"><?= e($statusLabels[$r['status']] ?? $r['status']) ?></span>
          <span class="share-link"><?= e(t('common.open')) ?> <span class="arr">&#8594;</span></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="<?= e(asset_url('/assets/js/forms-data-index.js')) ?>"></script>
