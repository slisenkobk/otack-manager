<?php
$isEdit = ($mode ?? 'create') === 'edit';
$palette = \App\Repository\ProjectRepository::PALETTE;
$initialColor = $isEdit
    ? ($project['color'] ?? '#1A1612')
    : $palette[array_rand($palette)];
?>
<form method="post" action="<?= $isEdit ? '/projects/' . (int)$project['id'] : '/projects' ?>" class="brief" style="max-width:none;">
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
  <div class="field">
    <label>Name</label>
    <input class="input" type="text" name="name" required autofocus value="<?= $isEdit ? e($project['name']) : '' ?>">
  </div>
  <div class="field" style="margin-top:14px;">
    <label>Color</label>
    <div class="color-picker-row">
      <input type="text" class="input" name="color" id="project-color-text" value="<?= e($initialColor) ?>" maxlength="7">
      <label class="color-swatch" style="background: <?= e($initialColor) ?>;">
        <input type="color" id="project-color-picker" value="<?= e($initialColor) ?>">
      </label>
    </div>
  </div>
  <div class="field" style="margin-top:14px;">
    <label>Description (optional)</label>
    <div class="wysiwyg-host">
      <div class="wysiwyg-editor"
           data-quill
           data-quill-target="#project-description-hidden"
           data-placeholder="What is this project about?"><?= $isEdit ? ($project['description'] ?? '') : '' ?></div>
    </div>
    <input type="hidden" name="description" id="project-description-hidden"
           value="<?= $isEdit ? e($project['description'] ?? '') : '' ?>">
  </div>
  <button class="submit" type="submit" style="margin-top:18px;">
    <?= $isEdit ? 'Save changes &#8594;' : 'Create project &#8594;' ?>
  </button>
</form>
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
<script type="module" src="/assets/js/wysiwyg.js"></script>
