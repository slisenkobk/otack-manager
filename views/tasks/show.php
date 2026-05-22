<?php use App\Service\Markdown; ?>
<div style="display:grid;grid-template-columns:1fr 280px;gap:40px;align-items:start;">
  <main>
    <p class="mono muted" style="font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:var(--ink-3);margin:0 0 8px;">
      <a href="/projects/<?= (int)$project['id'] ?>" style="color:var(--ink-3);text-decoration:none;"><?= e($project['name']) ?></a>
      / Task #<?= (int)$task['id'] ?>
    </p>

    <h1 class="task-title" contenteditable="true" spellcheck="false"
        style="font-size:32px;font-weight:700;letter-spacing:-0.02em;margin:0 0 24px;outline:none;border-bottom:1px dashed transparent;padding-bottom:4px;cursor:text;"
        data-task-id="<?= (int)$task['id'] ?>"><?= e($task['title']) ?></h1>

    <section class="task-description-section" style="margin-bottom:32px;">
      <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;">
        <h2 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:0;">Description</h2>
        <button class="btn-ghost" type="button" data-action="edit-description" style="font-size:12px;">Edit</button>
      </div>
      <div class="task-description-rendered" style="font-size:15px;line-height:1.6;color:var(--ink-2);">
        <?= $task['description'] ? Markdown::render((string)$task['description']) : '<span class="muted" style="color:var(--ink-3);">No description. Click Edit to add one.</span>' ?>
      </div>
      <div class="task-description-editor" style="display:none;">
        <textarea class="textarea" rows="6" placeholder="Markdown: **bold**, `code`, [link](https://…)"><?= e($task['description'] ?? '') ?></textarea>
        <div style="display:flex;gap:8px;margin-top:8px;">
          <button class="submit" type="button" data-action="save-description">Save</button>
          <button class="btn-ghost" type="button" data-action="cancel-description">Cancel</button>
        </div>
      </div>
    </section>

    <section style="margin-bottom:32px;">
      <h2 style="font-weight:600;font-size:14px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);margin:0 0 12px;">Attachments</h2>
      <?php
        $entityType = 'task';
        $entityId = (int)$task['id'];
        require APP_ROOT . '/views/partials/attachment-list.php';
      ?>
    </section>

    <section>
      <?php
        $entityType = 'task';
        $entityId = (int)$task['id'];
        $canPost = $canEdit;
        require APP_ROOT . '/views/partials/comment-thread.php';
      ?>
    </section>
  </main>

  <aside class="task-sidebar" data-task-id="<?= (int)$task['id'] ?>" data-project-id="<?= (int)$project['id'] ?>"
         style="border:1px solid var(--rule);padding:18px;background:var(--paper);border-radius:4px;">
    <h3 style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.15em;color:var(--ink-3);margin:0 0 16px;">Details</h3>

    <div class="field" style="margin-bottom:14px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Status</label>
      <select class="select" data-field="column_id">
        <?php foreach ($columns as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === (int)$task['column_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin-bottom:14px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Assignee</label>
      <select class="select" data-field="assignee_id">
        <option value="">— Unassigned —</option>
        <?php foreach ($members as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)($task['assignee_id'] ?? 0) ? 'selected' : '' ?>><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field" style="margin-bottom:14px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Due date</label>
      <input class="input" type="date" data-field="due_date" value="<?= e($task['due_date'] ?? '') ?>">
    </div>

    <div class="field" style="margin-bottom:14px;">
      <label style="font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.1em;">Tags</label>
      <?php
        $scope      = 'task';
        $entityType = 'task';
        $entityId   = (int)$task['id'];
        $current    = $taskTags ?? [];
        $all        = $allTaskTags ?? [];
        require APP_ROOT . '/views/partials/tag-picker.php';
      ?>
    </div>

    <div style="border-top:1px solid var(--rule);padding-top:12px;margin-top:18px;font-size:11px;color:var(--ink-3);">
      <div>Created by <strong style="color:var(--ink-2);"><?= e($createdBy['name'] ?? 'unknown') ?></strong></div>
      <div style="margin-top:4px;font-family:var(--font-mono);"><?= fmt_datetime($task['created_at']) ?></div>
    </div>

    <button class="btn-danger" type="button" data-action="delete-task"
            style="margin-top:18px;width:100%;padding:8px;font-size:12px;letter-spacing:.1em;text-transform:uppercase;">
      Delete task
    </button>
  </aside>
</div>

<script type="module" src="/assets/js/task-page.js"></script>
