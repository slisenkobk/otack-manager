<div class="page-actions head-with-actions">
  <form method="get" action="/forms" class="search-field search-field--with-submit m-0">
    <i class="fa-solid fa-magnifying-glass muted fz-12"></i>
    <input class="input input--inline" name="q" placeholder="<?= e(t('forms.search_placeholder')) ?>" value="<?= e($query ?? '') ?>">
    <button type="submit" class="search-field__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <a href="/forms/new" class="btn btn--secondary no-underline">+ <?= e(t('forms.new_form')) ?></a>
</div>

<?php if (!$forms): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= t('forms.empty', ['link' => '<a href="/forms/new">' . e(t('forms.build_first')) . '</a>']) ?></p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($forms as $f):
      $publicUrl = \abs_url('/f/' . $f['hash']);
      $descPreview = trim(strip_tags((string)$f['description']));
    ?>
      <div class="card card--clickable" data-href="/forms/<?= (int)$f['id'] ?>" data-form-id="<?= (int)$f['id'] ?>">
        <span class="corner-tag">F-<?= (int)$f['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($f['created_at']) ?></span>
        <div class="card-head">
          <div class="ini bg-accent"><i class="fa-solid fa-clipboard-list text-paper fz-16"></i></div>
          <div class="flex-1-min">
            <h3 class="name"><?= e($f['title']) ?></h3>
          </div>
        </div>
        <?php if ($descPreview !== ''): ?>
          <p class="card__desc"><?= e(mb_strimwidth($descPreview, 0, 140, '…')) ?></p>
        <?php endif; ?>
        <div class="form-card__url" data-stop>
          <i class="fa-solid fa-link"></i>
          <span class="form-card__url-text"><?= e($publicUrl) ?></span>
          <button type="button" class="form-card__url-copy" data-action="copy-link" data-url="<?= e($publicUrl) ?>" data-stop title="<?= e(t('forms.copy_link')) ?>"><i class="fa-regular fa-copy"></i></button>
        </div>
        <div class="card-row">
          <div class="flex-row-gap-6">
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn btn--ghost" data-stop title="<?= e(t('forms.open_public')) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <button type="button" class="btn btn--ghost" data-action="duplicate-form" data-stop title="<?= e(t('forms.duplicate_title')) ?>"><i class="fa-regular fa-clone"></i></button>
            <button type="button" class="btn btn--ghost text-accent" data-action="delete-form" data-stop title="<?= e(t('common.delete')) ?>"><i class="fa-solid fa-trash"></i></button>
          </div>
          <span class="share-link"><?= e(t('common.edit')) ?> <span class="arr">&#8594;</span></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="<?= e(asset_url('/assets/js/forms-index.js')) ?>"></script>
