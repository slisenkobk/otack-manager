<?php
// Inputs: page, bodyHtml (already sanitized), tags, comments, versionsCount,
//         canEdit, csrfToken, success, error
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
?>

<div class="page-actions">
  <a class="btn btn--ghost knowledge-back" href="/knowledge">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('knowledge.title')) ?>
  </a>
  <div class="page-actions__right">
    <a class="btn btn--ghost knowledge-back" href="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/versions">
      <i class="fa-solid fa-clock-rotate-left"></i>
      <?= e(t('knowledge.versions')) ?>
      <?php if ($versionsCount > 0): ?><span class="badge"><?= (int)$versionsCount ?></span><?php endif; ?>
    </a>
    <?php if (!empty($canEdit)): ?>
      <a class="btn btn--secondary" href="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/edit">
        <i class="fa-solid fa-pen"></i> <?= e(t('knowledge.edit')) ?>
      </a>
    <?php endif; ?>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <form method="post" action="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/delete" class="knowledge-inline-form"
            data-confirm="<?= e(t('knowledge.delete.confirm')) ?>"
            data-confirm-label="<?= e(t('knowledge.delete.confirm_label')) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <button type="submit" class="btn btn--danger">
          <i class="fa-solid fa-trash"></i> <?= e(t('common.delete')) ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<article class="knowledge-article">
  <header class="knowledge-article__head">
    <h1 class="knowledge-article__title"><?= e($page['title']) ?></h1>
    <div class="knowledge-article__meta muted fz-13">
      <?php if (!empty($page['category_name'])): ?>
        <a href="/knowledge?category=<?= e(rawurlencode((string)$page['category_slug'])) ?>"
           class="knowledge-list__cat"><?= e($page['category_name']) ?></a>
        <span>·</span>
      <?php endif; ?>
      <span><?= e(t('knowledge.list.by', ['name' => (string)($page['author_name'] ?? '—')])) ?></span>
      <span>·</span>
      <span><?= e(t('knowledge.updated_at', ['when' => fmt_datetime_full($page['updated_at'])])) ?></span>
    </div>
    <?php if (!empty($tags)): ?>
      <div class="knowledge-article__tags u-mt-space-3">
        <?php foreach ($tags as $tag): ?>
          <a class="knowledge-tag" href="/knowledge?tag=<?= (int)$tag['id'] ?>"
             data-bg="<?= e($tag['color'] ?? '#8B7C68') ?>"><?= e($tag['name']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </header>

  <div class="knowledge-article__body prose">
    <?php if (trim($bodyHtml) === ''): ?>
      <p class="muted"><?= e(t('knowledge.empty.body')) ?></p>
    <?php else: ?>
      <?= $bodyHtml /* already sanitized by Markdown::renderRich */ ?>
    <?php endif; ?>
  </div>
</article>

<section class="knowledge-comments" data-knowledge-comments
         data-entity-id="<?= (int)$page['id'] ?>"
         data-page-slug="<?= e((string)$page['slug']) ?>">
  <h2 class="knowledge-comments__title"><?= e(t('knowledge.comments.title', ['n' => count($comments ?? [])])) ?></h2>

  <form class="knowledge-comment-form" data-knowledge-comment-form>
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <textarea class="input" rows="3" name="body"
              placeholder="<?= e(t('knowledge.comments.placeholder')) ?>" data-comment-input></textarea>
    <div class="knowledge-comment-form__actions">
      <button type="submit" class="btn btn--primary"><?= e(t('knowledge.comments.submit')) ?></button>
    </div>
  </form>

  <div class="knowledge-comment-list u-mt-space-5" data-comment-list>
    <?php foreach (($comments ?? []) as $c): ?>
      <?php
        $authorAvatar = $c['author_avatar'] ?? null;
        $authorName   = $c['author_name'] ?? '—';
        $isMine       = (int)$c['user_id'] === (int)$user['id'];
        $canDelete    = $isMine || ($user['role'] ?? '') === 'admin';
      ?>
      <div class="knowledge-comment" data-comment-id="<?= (int)$c['id'] ?>">
        <div class="knowledge-comment__head">
          <?= user_avatar_html((int)$c['user_id'], $authorName, $authorAvatar, 'sm') ?>
          <strong><?= e($authorName) ?></strong>
          <span class="muted fz-12"><?= e(fmt_datetime_full($c['created_at'])) ?></span>
          <?php if ($canDelete): ?>
            <button type="button" class="btn btn--ghost btn--sm ml-auto" data-action="delete-comment"
                    data-confirm="<?= e(t('knowledge.comments.delete_confirm')) ?>"
                    data-confirm-label="<?= e(t('common.delete')) ?>">
              <i class="fa-solid fa-trash"></i>
            </button>
          <?php endif; ?>
        </div>
        <div class="knowledge-comment__body"><?= \App\Service\Markdown::render((string)$c['body']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<script type="module" src="<?= e(asset_url('/assets/js/knowledge.js')) ?>"></script>
