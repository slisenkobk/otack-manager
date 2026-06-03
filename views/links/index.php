<div class="page-actions" style="margin-bottom:var(--space-8);justify-content:space-between;gap:10px;">
  <form method="get" action="/links" class="search-field search-field--with-submit" style="margin:0;">
    <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted);font-size:12px;"></i>
    <input class="input input--inline" name="q" placeholder="<?= e(t('links.search_placeholder')) ?>" value="<?= e($query ?? '') ?>">
    <button type="submit" class="search-field__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <button type="button" class="btn btn--secondary" data-action="new-link">+ <?= e(t('links.new_link')) ?></button>
</div>

<?php if (!$links): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= t('links.empty', ['link' => '<a href="#" data-action="new-link">' . e(t('links.build_first')) . '</a>']) ?></p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($links as $l):
      $publicUrl = \abs_url('/s/' . $l['slug']);
      $isDisabled = (int)$l['is_disabled'] === 1;
      $s = $stats[(int)$l['id']] ?? ['total' => 0, 'unique' => 0];
    ?>
      <div class="card card--clickable<?= $isDisabled ? ' card--muted' : '' ?>" data-href="/links/<?= (int)$l['id'] ?>" data-link-id="<?= (int)$l['id'] ?>">
        <span class="corner-tag">L-<?= (int)$l['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($l['created_at']) ?></span>
        <div class="card-head">
          <div class="ini" style="background: var(--accent);"><i class="fa-solid fa-link" style="color:var(--paper);font-size:16px;"></i></div>
          <div style="flex:1;min-width:0;">
            <h3 class="name"><?= e($l['title'] ?: $l['slug']) ?></h3>
          </div>
        </div>
        <p class="card__desc" style="font-family:var(--font-mono);font-size:11px;color:var(--ink-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($l['target_url']) ?></p>
        <div class="form-card__url" data-stop>
          <i class="fa-solid fa-link"></i>
          <span class="form-card__url-text"><?= e($publicUrl) ?></span>
          <button type="button" class="form-card__url-copy" data-action="copy-link" data-url="<?= e($publicUrl) ?>" data-stop title="<?= e(t('links.copy_link')) ?>"><i class="fa-regular fa-copy"></i></button>
        </div>
        <div class="link-card__stats" style="display:flex;gap:14px;font-size:12px;color:var(--ink-3);margin-top:8px;">
          <span><strong style="color:var(--ink);"><?= (int)$s['total'] ?></strong> <?= e(t('links.stat.total')) ?></span>
          <span><strong style="color:var(--ink);"><?= (int)$s['unique'] ?></strong> <?= e(t('links.stat.unique')) ?></span>
          <?php if ($isDisabled): ?>
            <span class="badge badge--muted" style="margin-left:auto;"><?= e(t('links.status.disabled')) ?></span>
          <?php endif; ?>
        </div>
        <div class="card-row">
          <div style="display:flex;gap:6px;">
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn--ghost" data-stop title="<?= e(t('links.open_public')) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <button type="button" class="btn--ghost" data-action="toggle-link" data-stop title="<?= e($isDisabled ? t('links.action.enable') : t('links.action.disable')) ?>"><i class="fa-solid <?= $isDisabled ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i></button>
            <button type="button" class="btn--ghost" data-action="delete-link" data-stop title="<?= e(t('common.delete')) ?>" style="color:var(--accent);"><i class="fa-solid fa-trash"></i></button>
          </div>
          <span class="share-link"><?= e(t('common.edit')) ?> <span class="arr">&#8594;</span></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="<?= e(asset_url('/assets/js/links-index.js')) ?>"></script>
