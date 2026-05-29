<div class="page-actions" style="margin-bottom:var(--space-8);justify-content:space-between;gap:10px;">
  <form method="get" action="/forms" class="search-field search-field--with-submit" style="margin:0;">
    <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted);font-size:12px;"></i>
    <input class="input input--inline" name="q" placeholder="Search forms…" value="<?= e($query ?? '') ?>">
    <button type="submit" class="search-field__submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <a href="/forms/new" class="btn btn--secondary" style="text-decoration:none;">+ New form</a>
</div>

<?php if (!$forms): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text">No forms yet. <a href="/forms/new">Build your first one</a>.</p>
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
          <div class="ini" style="background: var(--accent);"><i class="fa-solid fa-clipboard-list" style="color:var(--paper);font-size:16px;"></i></div>
          <div style="flex:1;min-width:0;">
            <h3 class="name"><?= e($f['title']) ?></h3>
          </div>
        </div>
        <?php if ($descPreview !== ''): ?>
          <p class="card__desc"><?= e(mb_strimwidth($descPreview, 0, 140, '…')) ?></p>
        <?php endif; ?>
        <div class="form-card__url" data-stop>
          <i class="fa-solid fa-link"></i>
          <span class="form-card__url-text"><?= e($publicUrl) ?></span>
        </div>
        <div class="card-row">
          <div style="display:flex;gap:6px;">
            <button type="button" class="btn-ghost" data-action="copy-link" data-url="<?= e($publicUrl) ?>" data-stop title="Copy link"><i class="fa-regular fa-copy"></i></button>
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn-ghost" data-stop title="Open public"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <button type="button" class="btn-ghost" data-action="delete-form" data-stop title="Delete" style="color:var(--accent);"><i class="fa-solid fa-trash"></i></button>
          </div>
          <span class="share-link">Edit <span class="arr">&#8594;</span></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/forms-index.js"></script>
