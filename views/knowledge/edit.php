<?php
// Inputs: page (nullable), categories, allTags, pageTags, csrfToken, error
$isNew    = $page === null;
$action   = $isNew ? '/knowledge' : '/knowledge/' . rawurlencode((string)$page['slug']);
$pageTagIds = array_map(fn($t) => (int)$t['id'], $pageTags ?? []);
echo !empty($error) ? flash_meta($error, 'error') : '';
?>

<div class="page-actions">
  <a class="btn btn--ghost knowledge-back" href="<?= $isNew ? '/knowledge' : '/knowledge/' . e(rawurlencode((string)$page['slug'])) ?>">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('common.cancel')) ?>
  </a>
</div>

<form method="post" action="<?= e($action) ?>" class="knowledge-editor" data-knowledge-editor>
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

  <div class="field">
    <label for="f-title"><?= e(t('knowledge.form.title')) ?></label>
    <input id="f-title" class="input" type="text" name="title" required minlength="2"
           value="<?= e($page['title'] ?? '') ?>"
           placeholder="<?= e(t('knowledge.form.title_placeholder')) ?>">
  </div>

  <div class="field">
    <label for="f-category"><?= e(t('knowledge.form.category')) ?></label>
    <select id="f-category" class="input" name="category_id">
      <option value=""><?= e(t('knowledge.form.no_category')) ?></option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>"
                <?= (int)($page['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
          <?= e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if (!empty($allTags)): ?>
    <div class="field">
      <label><?= e(t('knowledge.form.tags')) ?></label>
      <div class="knowledge-tag-picker">
        <?php foreach ($allTags as $tag): ?>
          <label class="knowledge-tag-picker__item">
            <input type="checkbox" name="tag_ids[]" value="<?= (int)$tag['id'] ?>"
                   <?= in_array((int)$tag['id'], $pageTagIds, true) ? 'checked' : '' ?>>
            <span class="knowledge-tag" data-bg="<?= e($tag['color'] ?? '#8B7C68') ?>">
              <?= e($tag['name']) ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="field knowledge-editor__field">
    <div class="knowledge-editor__tabs">
      <button type="button" class="knowledge-editor__tab is-active" data-editor-tab="edit">
        <?= e(t('knowledge.editor.tab_edit')) ?>
      </button>
      <button type="button" class="knowledge-editor__tab" data-editor-tab="preview">
        <?= e(t('knowledge.editor.tab_preview')) ?>
      </button>
    </div>
    <div class="knowledge-editor__panes">
      <textarea id="f-body" class="input knowledge-editor__textarea" name="body_md" rows="22"
                placeholder="<?= e(t('knowledge.form.body_placeholder')) ?>"
                data-editor-source><?= e((string)($page['body_md'] ?? '')) ?></textarea>
      <div class="knowledge-editor__preview prose" data-editor-preview hidden>
        <p class="muted"><?= e(t('knowledge.editor.preview_loading')) ?></p>
      </div>
    </div>
    <small class="muted fz-12 knowledge-editor__hint"><?= e(t('knowledge.editor.help')) ?></small>
  </div>

  <div class="knowledge-editor__actions">
    <button type="submit" class="btn btn--primary">
      <?= e($isNew ? t('knowledge.form.create') : t('knowledge.form.save')) ?>
    </button>
  </div>
</form>

<script type="module" src="<?= e(asset_url('/assets/js/knowledge.js')) ?>"></script>
