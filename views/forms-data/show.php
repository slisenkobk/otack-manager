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
<div class="page-actions" style="margin-bottom:var(--space-8);">
  <a href="/forms-data" style="color:var(--ink-3);text-decoration:none;font-size:12px;"><i class="fa-solid fa-arrow-left"></i> All submissions</a>
  <span class="status status--<?= e($sub['status']) ?>"><?= e($statusLabels[$sub['status']] ?? $sub['status']) ?></span>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:24px;align-items:start;">
  <div>

    <div class="brief" style="max-width:none;">
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="font-size:20px;font-weight:600;margin:0;"><?= e($form['title']) ?></h2>
        <span class="mono" style="font-size:11px;color:var(--ink-3);">Submission #<?= (int)$sub['id'] ?> · <?= fmt_datetime($sub['created_at']) ?></span>
      </div>
      <?php if (!empty($form['description'])): ?>
        <p class="muted" style="margin:8px 0 0;font-size:13px;color:var(--ink-3);"><?= e($form['description']) ?></p>
      <?php endif; ?>
    </div>

    <div class="brief" style="max-width:none;margin-top:16px;">
      <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--ink-3);margin:0 0 14px;">Answers</h3>
      <?php foreach ($fields as $f):
        $v = $answers[$f['key']] ?? '';
        if (is_array($v)) $v = implode(', ', $v);
        $v = (string)$v;
      ?>
        <div class="field" style="margin-bottom:14px;">
          <label style="font-size:12px;color:var(--ink-3);"><?= e($f['label']) ?></label>
          <div style="margin-top:4px;font-size:14px;line-height:1.45;white-space:pre-wrap;<?= trim($v) === '' ? 'color:var(--ink-3);font-style:italic;' : '' ?>"><?= $v === '' ? '— empty' : nl2br(e($v)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($footer)): ?>
      <div class="brief" style="max-width:none;margin-top:16px;">
        <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--ink-3);margin:0 0 14px;">Contact info</h3>
        <?php foreach ([
          'company_name' => 'Company name',
          'email'        => 'Email',
          'phone'        => 'Phone',
          'address'      => 'Address',
          'note'         => 'Note',
        ] as $k => $label):
          $v = trim((string)($footer[$k] ?? ''));
          if ($v === '') continue;
        ?>
          <div class="field" style="margin-bottom:10px;">
            <label style="font-size:12px;color:var(--ink-3);"><?= e($label) ?></label>
            <div style="margin-top:2px;font-size:13px;white-space:pre-wrap;"><?= nl2br(e($v)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>

  <aside style="display:flex;flex-direction:column;gap:14px;" data-sub-id="<?= (int)$sub['id'] ?>">

    <div class="brief" style="max-width:none;">
      <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--ink-3);margin:0 0 12px;">Status</h3>
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
        <p class="muted" style="font-size:11px;color:var(--ink-3);margin:10px 0 0;">
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
      <div class="brief" style="max-width:none;">
        <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--ink-3);margin:0 0 12px;">Convert to</h3>
        <button type="button" class="btn btn--primary submit" style="width:100%;margin-bottom:8px;" data-action="convert-project">
          <i class="fa-solid fa-folder-plus"></i> New project
        </button>
        <div class="field">
          <label style="font-size:12px;color:var(--ink-3);">Or pick a project for a new task</label>
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
        <button type="button" class="btn-secondary" style="width:100%;margin-top:8px;" data-action="convert-task">
          <i class="fa-solid fa-list-check"></i> Create task
        </button>
      </div>
    <?php endif; ?>

    <button type="button" class="btn-danger" style="padding:8px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;" data-action="delete-sub">
      Delete submission
    </button>

  </aside>
</div>

<script type="module" src="/assets/js/forms-data-show.js"></script>
