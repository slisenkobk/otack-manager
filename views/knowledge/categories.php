<?php
// Inputs: categories, csrfToken, success, error
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
?>

<div class="page-actions">
  <a class="btn btn--ghost knowledge-back" href="/knowledge">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('knowledge.title')) ?>
  </a>
</div>

<section class="knowledge-cats">
  <h2 class="knowledge-cats__title"><?= e(t('knowledge.categories.title')) ?></h2>
  <p class="knowledge-cats__lede"><?= e(t('knowledge.categories.lede')) ?></p>

  <form method="post" action="/admin/knowledge/categories" class="knowledge-cats__create">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <input class="input knowledge-cats__create-input" type="text" name="name" required minlength="2"
           placeholder="<?= e(t('knowledge.categories.placeholder')) ?>">
    <button type="submit" class="btn btn--primary">
      <i class="fa-solid fa-plus"></i> <?= e(t('knowledge.categories.add')) ?>
    </button>
  </form>

  <?php if (!$categories): ?>
    <div class="empty-state">
      <p class="empty-state__text"><?= e(t('knowledge.categories.empty')) ?></p>
    </div>
  <?php else: ?>
    <ul class="knowledge-cats__list">
      <?php foreach ($categories as $c): ?>
        <li class="knowledge-cats__row">
          <form method="post" action="/admin/knowledge/categories/<?= (int)$c['id'] ?>" class="knowledge-cats__rename">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <input class="input knowledge-cats__rename-input" type="text" name="name"
                   value="<?= e($c['name']) ?>" required minlength="2">
            <button type="submit" class="btn btn--secondary btn--sm" title="<?= e(t('common.save')) ?>">
              <i class="fa-solid fa-check"></i>
            </button>
          </form>
          <code class="knowledge-cats__slug"><?= e((string)$c['slug']) ?></code>
          <form method="post" action="/admin/knowledge/categories/<?= (int)$c['id'] ?>/delete"
                class="knowledge-cats__delete"
                data-confirm="<?= e(t('knowledge.categories.delete_confirm')) ?>"
                data-confirm-label="<?= e(t('common.delete')) ?>">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <button type="submit" class="btn btn--danger btn--sm" title="<?= e(t('common.delete')) ?>">
              <i class="fa-solid fa-trash"></i>
            </button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script type="module" src="<?= e(asset_url('/assets/js/knowledge.js')) ?>"></script>
