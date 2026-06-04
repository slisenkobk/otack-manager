<?php
// Edit-only — project creation now happens through the index-page modal.
$palette      = \App\Repository\ProjectRepository::PALETTE;
$initialColor = $project['color'] ?? $palette[array_rand($palette)];
$fieldErrors  = $fieldErrors ?? [];
?>
<div class="page-form">
<form class="max-w-none" method="post" action="/projects/<?= (int)$project['id'] ?>" class="brief">
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
  <div class="field<?= isset($fieldErrors['name']) ? ' field--invalid' : '' ?>">
    <label for="f-project-name"><?= e(t('projects.form.name')) ?></label>
    <input id="f-project-name" class="input" type="text" name="name" required autofocus value="<?= e($project['name']) ?>">
    <?php if (isset($fieldErrors['name'])): ?>
      <div class="field__error"><?= e(t('errors.' . $fieldErrors['name'], t('errors.required'))) ?></div>
    <?php endif; ?>
  </div>
  <div class="field mt-14">
    <label for="project-color-text"><?= e(t('projects.form.color')) ?></label>
    <div class="color-picker-row">
      <input type="text" class="input" name="color" id="project-color-text" value="<?= e($initialColor) ?>" maxlength="7">
      <label class="color-swatch" style="background: <?= e($initialColor) ?>;">
        <input type="color" id="project-color-picker" value="<?= e($initialColor) ?>">
      </label>
    </div>
  </div>
  <div class="field mt-14">
    <label><?= e(t('projects.form.description')) ?></label>
    <div class="wysiwyg-host">
      <div class="wysiwyg-editor"
           data-quill
           data-quill-target="#project-description-hidden"
           data-placeholder="What is this project about?"><?= $project['description'] ?? '' ?></div>
    </div>
    <input type="hidden" name="description" id="project-description-hidden"
           value="<?= e($project['description'] ?? '') ?>">
  </div>
  <button class="submit mt-18" type="submit">
    <?= e(t('common.save')) ?> &#8594;
  </button>
</form>
</div>
<script>
(function () {
  const text   = document.getElementById('project-color-text');
  const picker = document.getElementById('project-color-picker');
  const swatch = picker?.closest('.color-swatch');
  if (!text || !picker || !swatch) return;
  function apply(v) {
    if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
    swatch.style.background = v;
    if (text.value.toLowerCase() !== v.toLowerCase()) text.value = v;
    if (picker.value.toLowerCase() !== v.toLowerCase()) picker.value = v;
  }
  picker.addEventListener('input', () => apply(picker.value));
  text.addEventListener('input', () => apply(text.value));
})();
</script>
