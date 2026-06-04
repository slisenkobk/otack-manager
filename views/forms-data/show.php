<?php
$statusLabels = [
  'new'               => 'New',
  'in_progress'       => 'In progress',
  'rejected'          => 'Rejected',
  'done'              => 'Done',
  'converted_task'    => 'Converted to task',
  'converted_project' => 'Converted to project',
];
$converted = in_array($sub['status'], ['converted_task', 'converted_project'], true);
?>
<div class="page-actions page-actions--inline u-mb-space-8">
  <a href="/forms-data" style="color:var(--ink-3);text-decoration:none;font-size:12px;"><i class="fa-solid fa-arrow-left"></i> All submissions</a>
  <span class="status status--<?= e($sub['status']) ?>"><?= e($statusLabels[$sub['status']] ?? $sub['status']) ?></span>
</div>

<div class="submission-detail">

  <!-- Left card: form title + answers + contact info as one continuous white surface. -->
  <section class="brief brief--wide submission-card">
    <header class="submission-card__head">
      <div>
        <h2 class="submission-card__title"><?= e($form['title']) ?></h2>
        <?php if (!empty($form['description'])): ?>
          <p class="submission-card__sub"><?= e($form['description']) ?></p>
        <?php endif; ?>
      </div>
      <span class="submission-card__meta">Submission #<?= (int)$sub['id'] ?> · <?= fmt_datetime($sub['created_at']) ?></span>
    </header>

    <div class="submission-card__section">
      <h3 class="submission-card__section-title">Answers</h3>
      <div class="submission-answers">
      <?php foreach ($fields as $f):
        $v = $answers[$f['key']] ?? '';
        if (is_array($v)) $v = implode(', ', $v);
        $v = (string)$v;
      ?>
        <div class="submission-answers__row">
          <div class="submission-answers__label"><?= e($f['label']) ?></div>
          <div class="submission-answers__value<?= trim($v) === '' ? ' submission-answers__value--empty' : '' ?>"><?= $v === '' ? '— empty' : nl2br(e($v)) ?></div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($footer)):
      $contactRows = [];
      foreach ([
        'company_name' => 'Company name',
        'email'        => 'Email',
        'phone'        => 'Phone',
        'address'      => 'Address',
        'note'         => 'Note',
      ] as $k => $label) {
        $v = trim((string)($footer[$k] ?? ''));
        if ($v !== '') $contactRows[$label] = $v;
      }
      if ($contactRows): ?>
        <div class="submission-card__section">
          <h3 class="submission-card__section-title">Contact info</h3>
          <div class="submission-answers">
          <?php foreach ($contactRows as $label => $v): ?>
            <div class="submission-answers__row">
              <div class="submission-answers__label"><?= e($label) ?></div>
              <div class="submission-answers__value"><?= nl2br(e($v)) ?></div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Right card: status + convert + delete as one continuous white surface. -->
  <aside class="brief brief--wide submission-aside" data-sub-id="<?= (int)$sub['id'] ?>">

    <div class="submission-card__section">
      <h3 class="submission-card__section-title">Status</h3>
      <div class="custom-select" data-custom-select>
        <button type="button" class="custom-select__btn">
          <span class="custom-select__icon"><span class="status-dot status-dot--<?= e($sub['status']) ?>"></span></span>
          <span class="custom-select__label"><?= e($statusLabels[$sub['status']] ?? $sub['status']) ?></span>
          <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
        </button>
        <div class="custom-select__pop" hidden>
          <div class="custom-select__opts">
            <?php foreach (['new', 'in_progress', 'rejected', 'done'] as $s): ?>
              <div class="custom-select__opt<?= $sub['status'] === $s ? ' is-selected' : '' ?>" data-value="<?= e($s) ?>">
                <span class="custom-select__icon"><span class="status-dot status-dot--<?= e($s) ?>"></span></span>
                <span class="custom-select__opt-label"><?= e($statusLabels[$s]) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <input type="hidden" data-status-select value="<?= e($sub['status']) ?>">
      </div>
      <?php if ($converted): ?>
        <p class="muted submission-card__hint">
          <?= e($statusLabels[$sub['status']]) ?>
          <?php if (!empty($sub['converted_task_id'])): ?>
            — <a href="/tasks/<?= (int)$sub['converted_task_id'] ?>">open task →</a>
          <?php elseif (!empty($sub['converted_project_id'])): ?>
            — <a href="/projects/<?= (int)$sub['converted_project_id'] ?>">open project →</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>

    <?php if (!$converted): ?>
      <div class="submission-card__section">
        <h3 class="submission-card__section-title">Convert to</h3>
        <button type="button" class="btn btn--primary submit submission-card__cta" data-action="convert-project">
          <i class="fa-solid fa-folder-plus"></i> New project
        </button>
        <div class="field" style="margin-top:var(--space-4);">
          <label class="fz-12 text-ink-3">Or pick a project for a new task</label>
          <div class="custom-select" data-custom-select<?= count($projects) > 6 ? ' data-custom-select-search' : '' ?>>
            <button type="button" class="custom-select__btn">
              <span class="custom-select__label" style="color:var(--ink-3);">— pick project —</span>
              <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
            </button>
            <div class="custom-select__pop" hidden>
              <?php if (count($projects) > 6): ?>
                <div class="custom-select__search">
                  <i class="fa-solid fa-magnifying-glass"></i>
                  <input type="text" placeholder="Search projects…" data-custom-select-search-input>
                </div>
              <?php endif; ?>
              <div class="custom-select__opts">
                <?php foreach ($projects as $p): ?>
                  <div class="custom-select__opt" data-value="<?= (int)$p['id'] ?>" data-search="<?= e(mb_strtolower((string)$p['name'])) ?>">
                    <span class="custom-select__opt-label"><?= e($p['name']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="custom-select__no-results" hidden>No matches.</div>
            </div>
            <input type="hidden" data-task-project value="">
          </div>
        </div>
        <button type="button" class="btn--secondary submission-card__cta" style="margin-top:var(--space-3);" data-action="convert-task">
          <i class="fa-solid fa-list-check"></i> Create task
        </button>
      </div>
    <?php endif; ?>

    <div class="submission-card__section submission-card__section--danger">
      <button type="button" class="btn--danger submission-card__delete" data-action="delete-sub">
        <i class="fa-solid fa-trash"></i> Delete submission
      </button>
    </div>

  </aside>
</div>

<script type="module" src="/assets/js/forms-data-show.js"></script>
