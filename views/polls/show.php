<?php
// Active / closed poll detail with [Statistics | Voters] tabs.
$statusKey = (string)$poll['status'];
$publicUrl = \abs_url('/p/' . $poll['hash']);
$pollId    = (int)$poll['id'];
$tab       = $tab ?? 'stats';
$voters    = $voters ?? [];
$page      = $page ?? 1;
$perPage   = $perPage ?? 50;
$pageCount = max(1, (int)ceil($total / $perPage));
?>
<div class="poll-show" data-poll-show data-poll-id="<?= $pollId ?>" data-poll-status="<?= e($statusKey) ?>">

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

  <div class="project-tabs" style="margin-bottom:var(--space-6, 16px);">
    <a class="project-tab<?= $tab === 'stats'  ? ' project-tab--active' : '' ?>" href="/polls/<?= $pollId ?>?tab=stats"><?= e(t('polls.stats_title')) ?></a>
    <a class="project-tab<?= $tab === 'voters' ? ' project-tab--active' : '' ?>" href="/polls/<?= $pollId ?>?tab=voters"><?= e(t('polls.voters_title')) ?> · <?= (int)$total ?></a>
  </div>

  <?php if ($tab === 'stats'): ?>
    <section class="builder-panel">
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
  <?php else: ?>
    <section class="builder-panel">
      <?php if (!$voters): ?>
        <p class="muted"><?= e(t('polls.voters_empty')) ?></p>
      <?php else: ?>
        <table class="voters-table">
          <thead>
            <tr>
              <th><?= e(t('polls.voters_col_contact')) ?></th>
              <th><?= e(t('polls.voters_col_choice')) ?></th>
              <th class="voters-table__when"><?= e(t('polls.voters_col_when')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($voters as $v): ?>
              <tr>
                <td><?= e((string)$v['contact']) ?></td>
                <td><?= e((string)($v['choice_label'] ?? $v['choice_key'])) ?></td>
                <td class="voters-table__when"><?= e(fmt_datetime($v['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($pageCount > 1): ?>
          <div class="pager" style="margin-top:12px;display:flex;gap:8px;justify-content:center;align-items:center;">
            <?php if ($page > 1): ?>
              <a class="btn-ghost" href="/polls/<?= $pollId ?>?tab=voters&page=<?= $page - 1 ?>"><i class="fa-solid fa-arrow-left"></i></a>
            <?php endif; ?>
            <span class="muted" style="font-size:12px;">
              <?= e(t('polls.voters_pager', ['page' => $page, 'total' => $pageCount])) ?>
            </span>
            <?php if ($page < $pageCount): ?>
              <a class="btn-ghost" href="/polls/<?= $pollId ?>?tab=voters&page=<?= $page + 1 ?>"><i class="fa-solid fa-arrow-right"></i></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

</div>

<script type="module" src="<?= e(asset_url('/assets/js/polls-index.js')) ?>"></script>
