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
      <label class="color-swatch" data-bg="<?= e($initialColor) ?>">
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
  <div class="field mt-14">
    <label for="f-project-repo-url"><?= e(t('projects.form.repo_url')) ?></label>
    <input id="f-project-repo-url" class="input" type="text" name="repo_url"
           value="<?= e($project['repo_url'] ?? '') ?>" maxlength="500"
           placeholder="git@github.com:org/repo.git">
  </div>
  <div class="field mt-14">
    <label for="f-project-default-branch"><?= e(t('projects.form.default_branch')) ?></label>
    <input id="f-project-default-branch" class="input" type="text" name="default_branch"
           value="<?= e($project['default_branch'] ?? '') ?>" maxlength="100" placeholder="main">
  </div>
  <div class="field mt-14">
    <label for="f-project-dev-branch"><?= e(t('projects.form.dev_branch')) ?></label>
    <input id="f-project-dev-branch" class="input" type="text" name="dev_branch"
           value="<?= e($project['dev_branch'] ?? '') ?>" maxlength="100" placeholder="develop">
  </div>
  <div class="field mt-14">
    <label for="f-project-dev-url"><?= e(t('projects.form.dev_url')) ?></label>
    <input id="f-project-dev-url" class="input" type="text" name="dev_url"
           value="<?= e($project['dev_url'] ?? '') ?>" maxlength="500"
           placeholder="https://dev.example.com">
  </div>
  <div class="field mt-14">
    <label for="f-project-agent-instructions"><?= e(t('projects.form.agent_instructions')) ?></label>
    <textarea id="f-project-agent-instructions" class="input" name="agent_instructions"
              rows="6"><?= e($project['agent_instructions'] ?? '') ?></textarea>
    <div class="field__hint"><?= e(t('projects.form.agent_hint')) ?></div>
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
