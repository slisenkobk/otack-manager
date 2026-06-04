<?php
$statusLabels = [
    ''       => t('polls.status.any'),
    'draft'  => t('polls.status.draft'),
    'active' => t('polls.status.active'),
    'closed' => t('polls.status.closed'),
];
?>
<div class="page-actions page-actions--listing">
  <form method="get" action="/polls" class="search-field search-field--with-submit m-0">
    <i class="fa-solid fa-magnifying-glass muted fz-12"></i>
    <input class="input input--inline" name="q" placeholder="<?= e(t('polls.search_placeholder')) ?>" value="<?= e($query ?? '') ?>">
    <?php if (!empty($status)): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <button type="submit" class="search-field__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <div class="polls-filters">
    <div class="polls-filters__pills">
      <?php foreach ($statusLabels as $code => $label): ?>
        <?php $isActive = ($status ?? '') === $code; ?>
        <a href="<?= e($code === '' ? '/polls' : '/polls?status=' . $code) ?>"
           class="btn btn--small no-underline <?= $isActive ? 'btn--primary' : 'btn--ghost' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <a href="/polls/new" class="btn btn--secondary no-underline">+ <?= e(t('polls.new_poll')) ?></a>
  </div>
</div>

<?php if (!$polls): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= t('polls.empty', ['link' => '<a href="/polls/new">' . e(t('polls.build_first')) . '</a>']) ?></p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($polls as $p):
      $publicUrl = \abs_url('/p/' . $p['hash']);
      $descPreview = trim(strip_tags((string)($p['description'] ?? '')));
      $voteCount   = (int)($counts[(int)$p['id']] ?? 0);
      $statusKey   = (string)$p['status'];
    ?>
      <div class="card card--clickable" data-href="/polls/<?= (int)$p['id'] ?>" data-poll-id="<?= (int)$p['id'] ?>">
        <span class="corner-tag">P-<?= (int)$p['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($p['created_at']) ?></span>
        <div class="card-head">
          <div class="ini bg-accent"><i class="fa-solid fa-square-poll-vertical text-paper fz-16"></i></div>
          <div class="flex-1-min">
            <h3 class="name"><?= e($p['title']) ?></h3>
            <p class="muted card__meta-line">
              <span class="poll-status poll-status--<?= e($statusKey) ?>"><?= e(t('polls.status.' . $statusKey)) ?></span>
              · <?= e(t('polls.votes_count', ['n' => $voteCount])) ?>
            </p>
          </div>
        </div>
        <?php if ($descPreview !== ''): ?>
          <p class="card__desc"><?= e(mb_strimwidth($descPreview, 0, 140, '…')) ?></p>
        <?php endif; ?>
        <?php if ($statusKey !== 'draft'): ?>
          <div class="form-card__url" data-stop>
            <i class="fa-solid fa-link"></i>
            <span class="form-card__url-text"><?= e($publicUrl) ?></span>
            <button type="button" class="form-card__url-copy" data-action="copy-link" data-url="<?= e($publicUrl) ?>" data-stop title="<?= e(t('polls.copy_link')) ?>"><i class="fa-regular fa-copy"></i></button>
          </div>
        <?php endif; ?>
        <div class="card-row">
          <div class="flex-row-gap-6">
            <?php if ($statusKey !== 'draft'): ?>
              <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn--ghost" data-stop title="<?= e(t('polls.open_public')) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            <?php endif; ?>
            <button type="button" class="btn--ghost" data-action="duplicate-poll" data-stop title="<?= e(t('polls.duplicate_title')) ?>"><i class="fa-regular fa-clone"></i></button>
            <button type="button" class="btn--ghost text-accent" data-action="delete-poll" data-stop title="<?= e(t('common.delete')) ?>"><i class="fa-solid fa-trash"></i></button>
          </div>
          <span class="share-link"><?= e($statusKey === 'draft' ? t('common.edit') : t('polls.open_admin')) ?> <span class="arr">&#8594;</span></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="<?= e(asset_url('/assets/js/polls-index.js')) ?>"></script>
