<?php
// Active / closed poll detail. Step 4 expands this with proper tabs (stats + voters);
// for now it surfaces the basics: status, vote tally, lifecycle controls.
$statusKey = (string)$poll['status'];
$publicUrl = \abs_url('/p/' . $poll['hash']);
?>
<div class="poll-show" data-poll-show data-poll-id="<?= (int)$poll['id'] ?>" data-poll-status="<?= e($statusKey) ?>">

  <div class="builder-toolbar">
    <div class="builder-toolbar__left">
      <a href="/polls" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('polls.all_polls_link')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <?php if ($statusKey === 'active'): ?>
        <button type="button" class="btn-secondary" data-action="close-poll"
                data-confirm="<?= e(t('polls.close_confirm')) ?>"><i class="fa-solid fa-stop"></i> <?= e(t('polls.close')) ?></button>
      <?php endif; ?>
      <?php if ($statusKey === 'closed' && !empty($poll['project_id']) && empty($poll['summary_task_id'])): ?>
        <button type="button" class="btn btn--primary" data-action="create-summary-task"><i class="fa-solid fa-list-check"></i> <?= e(t('polls.create_summary_task')) ?></button>
      <?php elseif ($statusKey === 'closed' && !empty($poll['summary_task_id'])): ?>
        <a class="btn-secondary" href="/tasks/<?= (int)$poll['summary_task_id'] ?>" style="text-decoration:none;">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('polls.view_summary_task')) ?>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e($poll['title']) ?></h2>
    <p style="margin:0 0 8px;">
      <span class="poll-status poll-status--<?= e($statusKey) ?>"><?= e(t('polls.status.' . $statusKey)) ?></span>
      · <?= e(t('polls.votes_count', ['n' => $total])) ?>
    </p>
    <?php if (!empty($poll['description'])): ?>
      <div class="poll-show__description"><?= $poll['description'] ?></div>
    <?php endif; ?>
    <div class="form-card__url" style="margin-top:12px;">
      <i class="fa-solid fa-link"></i>
      <span class="form-card__url-text"><?= e($publicUrl) ?></span>
      <button type="button" class="form-card__url-copy" data-action="copy-url" data-url="<?= e($publicUrl) ?>" title="<?= e(t('polls.copy_link')) ?>"><i class="fa-regular fa-copy"></i></button>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.stats_title')) ?></h2>
    <?php if (!$tally): ?>
      <p class="muted"><?= e(t('polls.stats_empty')) ?></p>
    <?php else: ?>
      <div class="poll-stats">
        <?php foreach ($tally as $row): ?>
          <div class="poll-stats__row">
            <div class="poll-stats__label">
              <span><?= e($row['label']) ?></span>
              <span class="poll-stats__count"><?= (int)$row['count'] ?> · <?= e($row['pct']) ?>%</span>
            </div>
            <div class="poll-stats__bar">
              <div class="poll-stats__bar-fill" style="width: <?= e(min(100, max(0, (float)$row['pct']))) ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

</div>

<script type="module" src="<?= e(asset_url('/assets/js/polls-index.js')) ?>"></script>
