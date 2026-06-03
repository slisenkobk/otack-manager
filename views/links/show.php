<?php
$publicUrl = \abs_url('/s/' . $link['slug']);
$maxDay = 1;
foreach ($byDay as $d) { if ($d['total'] > $maxDay) $maxDay = $d['total']; }
$isDisabled = (int)$link['is_disabled'] === 1;
?>

<div class="form-builder" data-link-edit data-link-id="<?= (int)$link['id'] ?>">

  <div class="builder-toolbar" data-builder-toolbar>
    <div class="builder-toolbar__left">
      <a href="/links" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('links.all_links')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <a class="btn--secondary" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('links.open_public')) ?></a>
      <button type="button" class="btn btn--primary submit" data-action="save-link"><i class="fa-solid fa-check"></i> <?= e(t('common.save')) ?></button>
    </div>
  </div>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('links.public_url')) ?></h2>
    <div class="public-url-row" data-public-url-row data-link-id="<?= (int)$link['id'] ?>">
      <a class="public-url-row__url" data-public-url href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">
        <i class="fa-solid fa-link"></i>
        <span data-public-url-text><?= e($publicUrl) ?></span>
      </a>
      <button type="button" class="btn--secondary" data-action="copy-url"><i class="fa-regular fa-copy"></i> <?= e(t('forms.copy')) ?></button>
      <button type="button" class="btn--ghost" data-action="rotate-url" title="<?= e(t('links.rotate_title')) ?>">
        <i class="fa-solid fa-arrows-rotate"></i> <?= e(t('forms.rotate')) ?>
      </button>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('links.basics')) ?></h2>
    <div class="builder-grid">
      <div class="field builder-grid__span">
        <label for="f-link-target"><?= e(t('links.field.target_url')) ?></label>
        <input id="f-link-target" class="input" type="url" data-link-target value="<?= e($link['target_url']) ?>" placeholder="https://example.com/landing">
      </div>
      <div class="field builder-grid__span">
        <label for="f-link-title"><?= e(t('links.field.title')) ?></label>
        <input id="f-link-title" class="input" type="text" data-link-title value="<?= e($link['title'] ?? '') ?>" placeholder="<?= e(t('links.field.title_placeholder')) ?>" maxlength="200">
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('links.stats.title')) ?></h2>
    <div class="link-stats">
      <div class="link-stats__numbers">
        <h3 class="link-stats__heading"><?= e(t('links.stats.totals')) ?></h3>
        <div class="link-stats__cell">
          <div class="link-stats__num"><?= (int)$stats['total'] ?></div>
          <div class="link-stats__label"><?= e(t('links.stat.total')) ?></div>
        </div>
        <div class="link-stats__cell">
          <div class="link-stats__num"><?= (int)$stats['unique'] ?></div>
          <div class="link-stats__label"><?= e(t('links.stat.unique')) ?></div>
        </div>
      </div>

      <div class="link-stats__chart" aria-label="<?= e(t('links.stats.daily_30')) ?>">
        <h3 class="link-stats__heading"><?= e(t('links.stats.daily_30')) ?></h3>
        <div class="link-stats__bars">
          <?php foreach ($byDay as $d): $h = $maxDay > 0 ? max(2, (int)round($d['total'] / $maxDay * 100)) : 2; ?>
            <span class="link-stats__bar" style="height:<?= $h ?>%;" title="<?= e($d['date']) ?>: <?= (int)$d['total'] ?> (<?= (int)$d['unique'] ?> unique)"></span>
          <?php endforeach; ?>
        </div>
        <div class="link-stats__bars-axis">
          <span><?= e($byDay[0]['date'] ?? '') ?></span>
          <span><?= e(end($byDay)['date'] ?? '') ?></span>
        </div>
      </div>

      <div class="link-stats__referers">
        <h3 class="link-stats__heading"><?= e(t('links.stats.referers')) ?></h3>
        <?php if (!$referers): ?>
          <p class="muted" style="font-size:12px;color:var(--ink-3);"><?= e(t('links.stats.referers_empty')) ?></p>
        <?php else: ?>
          <ul class="link-stats__ref-list">
            <?php foreach ($referers as $r): ?>
              <li><span><?= e($r['host']) ?></span><strong><?= (int)$r['count'] ?></strong></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('links.danger.title')) ?></h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn--secondary" data-action="toggle-link">
        <i class="fa-solid <?= $isDisabled ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
        <span data-toggle-label><?= e($isDisabled ? t('links.action.enable') : t('links.action.disable')) ?></span>
      </button>
      <button type="button" class="btn--secondary" data-action="delete-link" style="color:var(--accent);">
        <i class="fa-solid fa-trash"></i> <?= e(t('common.delete')) ?>
      </button>
    </div>
  </section>

</div>

<script type="module" src="<?= e(asset_url('/assets/js/links-show.js')) ?>"></script>
