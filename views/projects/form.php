<?php $isEdit = ($mode ?? 'create') === 'edit'; ?>
<div class="section-head">
  <div class="num"><?= $isEdit ? '02' : '01' ?></div>
  <div class="title"><?= $isEdit ? 'Edit project' : 'New project' ?></div>
  <div class="rule"></div>
</div>

<form method="post" action="<?= $isEdit ? '/projects/' . (int)$project['id'] : '/projects' ?>" class="brief" style="max-width:680px;">
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
  <div class="field">
    <label>Name</label>
    <input class="input" type="text" name="name" required autofocus value="<?= $isEdit ? e($project['name']) : '' ?>">
  </div>
  <div class="field" style="margin-top:14px;">
    <label>Description (optional)</label>
    <?php if ($isEdit): ?>
      <textarea class="textarea" name="description" rows="5"><?= e($project['description'] ?? '') ?></textarea>
    <?php else: ?>
      <textarea class="textarea" name="description" rows="5" placeholder="What is this project about?"></textarea>
    <?php endif; ?>
  </div>
  <button class="submit" type="submit" style="margin-top:18px;">
    <?= $isEdit ? 'Save changes &#8594;' : 'Create project &#8594;' ?>
  </button>
</form>
