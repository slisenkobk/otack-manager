<?php // Inputs: page, version, bodyHtml ?>

<div class="page-actions">
  <a class="btn btn--ghost knowledge-back" href="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/versions">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('knowledge.versions')) ?>
  </a>
</div>

<article class="knowledge-article u-mt-space-6">
  <header class="knowledge-article__head">
    <div class="muted fz-13 u-mb-space-2">
      <?= e(t('knowledge.versions.heading_for', [
          'parent' => (string)$page['title'],
          'id'     => '#' . (int)$version['id'],
      ])) ?>
    </div>
    <h1 class="knowledge-article__title">
      <?= e((string)$version['title']) ?>
    </h1>
    <div class="knowledge-article__meta muted fz-13">
      <?php if (!empty($version['note'])): ?>
        <strong><?= e((string)$version['note']) ?></strong>
        <span>·</span>
      <?php endif; ?>
      <span><?= e(t('knowledge.list.by', ['name' => (string)($version['snapshot_by_name'] ?? '—')])) ?></span>
      <span>·</span>
      <span><?= e(fmt_datetime_full($version['snapshot_at'])) ?></span>
    </div>
  </header>

  <div class="knowledge-article__body prose">
    <?php if (trim($bodyHtml) === ''): ?>
      <p class="muted"><?= e(t('knowledge.empty.body')) ?></p>
    <?php else: ?>
      <?= $bodyHtml ?>
    <?php endif; ?>
  </div>

  <p class="muted fz-12 u-mt-space-6">
    <?= e(t('knowledge.versions.read_only_note')) ?>
  </p>
</article>
