<?php
// Active / closed poll detail with [Statistics | Voters] tabs.
$statusKey = (string)$poll['status'];
$publicUrl = \abs_url('/p/' . $poll['hash']);
$pollId    = (int)$poll['id'];
$tab       = $tab ?? 'stats';
$voters    = $voters ?? [];
$perPage   = $perPage ?? 10;
// "Load more" appears only if the first server-rendered batch likely has
// continuation; comparing batch count to total avoids a redundant 0-row fetch.
$hasMore   = count($voters) < (int)$total;
?>
<div class="poll-show" data-poll-show data-poll-id="<?= $pollId ?>" data-poll-status="<?= e($statusKey) ?>">

  <div class="builder-toolbar">
    <div class="builder-toolbar__left">
      <a href="/polls" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('polls.all_polls_link')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <?php if ($statusKey === 'active'): ?>
        <button type="button" class="btn--secondary" data-action="close-poll"
                data-confirm="<?= e(t('polls.close_confirm')) ?>"><i class="fa-solid fa-stop"></i> <?= e(t('polls.close')) ?></button>
      <?php endif; ?>
      <?php if ($statusKey === 'closed' && !empty($poll['project_id']) && empty($poll['summary_task_id'])): ?>
        <button type="button" class="btn btn--primary" data-action="create-summary-task"><i class="fa-solid fa-list-check"></i> <?= e(t('polls.create_summary_task')) ?></button>
      <?php elseif ($statusKey === 'closed' && !empty($poll['summary_task_id'])): ?>
        <a class="btn--secondary no-underline" href="/tasks/<?= (int)$poll['summary_task_id'] ?>">
          <i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('polls.view_summary_task')) ?>
        </a>
      <?php endif; ?>
    </div>
  </div>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e($poll['title']) ?></h2>
    <p class="poll-show__status-line">
      <span class="poll-status poll-status--<?= e($statusKey) ?>"><?= e(t('polls.status.' . $statusKey)) ?></span>
      · <?= e(t('polls.votes_count', ['n' => $total])) ?>
    </p>
    <?php if (!empty($poll['description'])): ?>
      <div class="poll-show__description"><?= $poll['description'] ?></div>
    <?php endif; ?>
    <div class="form-card__url poll-show__url">
      <i class="fa-solid fa-link"></i>
      <span class="form-card__url-text"><?= e($publicUrl) ?></span>
      <button type="button" class="form-card__url-copy" data-action="copy-url" data-url="<?= e($publicUrl) ?>" title="<?= e(t('polls.copy_link')) ?>"><i class="fa-regular fa-copy"></i></button>
    </div>
  </section>

  <div class="project-tabs project-tabs--spaced">
    <a class="project-tab<?= $tab === 'stats'   ? ' project-tab--active' : '' ?>" href="/polls/<?= $pollId ?>?tab=stats"><?= e(t('polls.stats_title')) ?></a>
    <a class="project-tab<?= $tab === 'voters'  ? ' project-tab--active' : '' ?>" href="/polls/<?= $pollId ?>?tab=voters"><?= e(t('polls.voters_title')) ?> · <?= (int)$total ?></a>
    <a class="project-tab<?= $tab === 'project' ? ' project-tab--active' : '' ?>" href="/polls/<?= $pollId ?>?tab=project"><?= e(t('polls.project_tab_title')) ?></a>
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
  <?php elseif ($tab === 'project'): ?>
    <section class="builder-panel" data-poll-project-edit data-poll-id="<?= $pollId ?>">
      <p class="muted field-hint"><?= e(t('polls.project_section_hint')) ?></p>
      <div class="field">
        <label><?= e(t('polls.integration.project_label')) ?></label>
        <div class="custom-select" data-custom-select data-poll-project-wrap>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label">
              <?= $project ? e($project['name']) : e(t('polls.integration.no_project')) ?>
            </span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <div class="custom-select__opt<?= empty($poll['project_id']) ? ' is-selected' : '' ?>" data-value="">
                <span class="custom-select__opt-label"><?= e(t('polls.integration.no_project')) ?></span>
              </div>
              <?php foreach (($projects ?? []) as $p): ?>
                <div class="custom-select__opt<?= (int)$p['id'] === (int)($poll['project_id'] ?? 0) ? ' is-selected' : '' ?>" data-value="<?= (int)$p['id'] ?>">
                  <span class="custom-select__opt-label"><?= e($p['name']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" data-poll-project-input value="<?= e((string)($poll['project_id'] ?? '')) ?>">
        </div>
      </div>
      <div class="poll-show__actions-end">
        <button type="button" class="btn btn--primary submit" data-action="save-project">
          <i class="fa-solid fa-check"></i> <?= e(t('polls.project_save')) ?>
        </button>
      </div>
    </section>
  <?php else: ?>
    <section class="builder-panel">
      <?php if (!$voters): ?>
        <p class="muted"><?= e(t('polls.voters_empty')) ?></p>
      <?php else: ?>
        <table class="voters-table" data-voters-table data-poll-id="<?= $pollId ?>">
          <thead>
            <tr>
              <th><?= e(t('polls.voters_col_contact')) ?></th>
              <th><?= e(t('polls.voters_col_choice')) ?></th>
              <th class="voters-table__when"><?= e(t('polls.voters_col_when')) ?></th>
            </tr>
          </thead>
          <tbody data-voters-tbody>
            <?php foreach ($voters as $v): ?>
              <tr>
                <td><?= e((string)$v['contact']) ?></td>
                <td><?= e((string)($v['choice_label'] ?? $v['choice_key'])) ?></td>
                <td class="voters-table__when"><?= e(fmt_datetime($v['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($hasMore): ?>
          <div class="poll-show__load-more">
            <button type="button" class="btn--secondary load-more-voters"
                    data-offset="<?= count($voters) ?>">
              <i class="fa-solid fa-chevron-down"></i> <?= e(t('polls.voters_load_more')) ?>
            </button>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>

</div>

<script type="module" src="<?= e(asset_url('/assets/js/polls-index.js')) ?>"></script>
