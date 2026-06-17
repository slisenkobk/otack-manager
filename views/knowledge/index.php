<?php
// Inputs: rows, total, page, perPage, categories, counts, tags, categorySlug,
//         tagId, q, canEdit, csrfToken, success, error
$totalCount        = (int)($counts['total'] ?? 0);
$byCategoryCounts  = (array)($counts['byCategory'] ?? []);
$uncategorisedCount = (int)($counts['uncategorised'] ?? 0);

$baseLink = function (?string $cat = '__keep__', ?int $tag = -1, ?string $query = '__keep__') use ($categorySlug, $tagId, $q): string {
    $effCat   = $cat   === '__keep__' ? ($categorySlug ?? '') : ($cat ?? '');
    $effTag   = $tag   === -1         ? (int)($tagId ?? 0)    : (int)($tag ?? 0);
    $effQuery = $query === '__keep__' ? ($q ?? '')            : ($query ?? '');
    $parts = [];
    if ($effCat   !== '') $parts[] = 'category=' . urlencode($effCat);
    if ($effTag   > 0)    $parts[] = 'tag=' . $effTag;
    if ($effQuery !== '') $parts[] = 'q=' . urlencode($effQuery);
    return '/knowledge' . ($parts ? '?' . implode('&', $parts) : '');
};

echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';
?>

<div class="knowledge-layout">
  <aside class="knowledge-rail">
    <div class="knowledge-rail__panel">
      <form method="get" action="/knowledge" class="knowledge-rail__search">
        <i class="fa-solid fa-magnifying-glass knowledge-rail__search-icon" aria-hidden="true"></i>
        <input class="input knowledge-rail__search-input" name="q" value="<?= e($q ?? '') ?>"
               placeholder="<?= e(t('knowledge.search_placeholder')) ?>"
               aria-label="<?= e(t('common.search')) ?>">
        <?php if (($categorySlug ?? '') !== ''): ?>
          <input type="hidden" name="category" value="<?= e($categorySlug) ?>">
        <?php endif; ?>
        <?php if (!empty($tagId)): ?>
          <input type="hidden" name="tag" value="<?= (int)$tagId ?>">
        <?php endif; ?>
      </form>
    </div>

    <div class="knowledge-rail__panel">
      <h3 class="knowledge-rail__heading"><?= e(t('knowledge.rail.categories')) ?></h3>
      <ul class="knowledge-rail__list">
        <li>
          <a class="knowledge-rail__link<?= ($categorySlug ?? '') === '' ? ' is-active' : '' ?>"
             href="<?= e($baseLink(null)) ?>">
            <span><?= e(t('knowledge.filter.all_categories')) ?></span>
            <span class="knowledge-rail__count"><?= $totalCount ?></span>
          </a>
        </li>
        <?php foreach (($categories ?? []) as $c): ?>
          <?php $cnt = (int)($byCategoryCounts[(int)$c['id']] ?? 0); ?>
          <li>
            <a class="knowledge-rail__link<?= ($categorySlug ?? '') === $c['slug'] ? ' is-active' : '' ?>"
               href="<?= e($baseLink((string)$c['slug'])) ?>">
              <span><?= e($c['name']) ?></span>
              <span class="knowledge-rail__count"><?= $cnt ?></span>
            </a>
          </li>
        <?php endforeach; ?>
        <?php if ($uncategorisedCount > 0): ?>
          <li class="muted fz-12 u-mt-space-2"><?= e(t('knowledge.rail.uncategorised', ['n' => $uncategorisedCount])) ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a class="btn btn--ghost btn--sm knowledge-rail__manage" href="/admin/knowledge/categories">
        <i class="fa-solid fa-folder-tree"></i> <?= e(t('knowledge.manage_categories')) ?>
      </a>
    <?php endif; ?>
  </aside>

  <section class="knowledge-main">
    <header class="knowledge-main__head">
      <h2 class="knowledge-main__title">
        <?php if (($categorySlug ?? '') !== ''): ?>
          <?php
            $catName = '';
            foreach (($categories ?? []) as $c) { if ($c['slug'] === $categorySlug) { $catName = $c['name']; break; } }
          ?>
          <?= e($catName !== '' ? $catName : t('knowledge.filter.all_categories')) ?>
        <?php else: ?>
          <?= e(t('knowledge.filter.all_categories')) ?>
        <?php endif; ?>
        <span class="muted fz-13" style="margin-left:var(--space-2);">· <?= (int)($total ?? 0) ?></span>
      </h2>
      <div class="knowledge-main__actions">
        <?php if (!empty($canEdit)): ?>
          <a class="btn btn--primary btn--sm" href="/knowledge/new">
            <i class="fa-solid fa-plus"></i> <?= e(t('knowledge.new')) ?>
          </a>
        <?php endif; ?>
      </div>
    </header>

    <?php if (!empty($tags)): ?>
      <div class="knowledge-tagstrip">
        <span class="knowledge-tagstrip__label"><?= e(t('knowledge.filter.tags_label')) ?></span>
        <a class="knowledge-tag<?= empty($tagId) ? ' is-active' : '' ?>"
           href="<?= e($baseLink('__keep__', 0)) ?>">
          <?= e(t('knowledge.filter.any_tag')) ?>
        </a>
        <?php foreach ($tags as $tagRow): ?>
          <a class="knowledge-tag<?= (int)$tagId === (int)$tagRow['id'] ? ' is-active' : '' ?>"
             href="<?= e($baseLink('__keep__', (int)$tagRow['id'])) ?>"
             data-bg="<?= e($tagRow['color'] ?? '#8B7C68') ?>">
            <?= e($tagRow['name']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="empty-state">
        <p class="empty-state__text">
          <?php if (($q ?? '') !== '' || ($categorySlug ?? '') !== '' || !empty($tagId)): ?>
            <?= e(t('knowledge.empty.match')) ?>
          <?php else: ?>
            <?= e(t('knowledge.empty.none')) ?>
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <ul class="knowledge-list">
        <?php foreach ($rows as $row): ?>
          <li class="knowledge-list__item">
            <a class="knowledge-list__title" href="/knowledge/<?= e(rawurlencode((string)$row['slug'])) ?>">
              <?= e($row['title']) ?>
            </a>
            <div class="knowledge-list__meta">
              <?php if (!empty($row['category_name'])): ?>
                <span class="knowledge-list__cat"><?= e($row['category_name']) ?></span>
                <span class="knowledge-list__dot">·</span>
              <?php endif; ?>
              <span><?= e(t('knowledge.list.by', ['name' => (string)($row['author_name'] ?? '—')])) ?></span>
              <span class="knowledge-list__dot">·</span>
              <span class="data-table__meta-time"><?= e(fmt_datetime_full($row['updated_at'])) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>

      <?php
        $pages = max(1, (int)ceil(($total ?? 0) / max(1, (int)$perPage)));
        $hrefBuilder = function (int $p) use ($categorySlug, $tagId, $q) {
            $parts = ['page=' . $p];
            if (($categorySlug ?? '') !== '') $parts[] = 'category=' . urlencode((string)$categorySlug);
            if (!empty($tagId))               $parts[] = 'tag=' . (int)$tagId;
            if (!empty($q))                    $parts[] = 'q=' . urlencode($q);
            return '/knowledge?' . implode('&', $parts);
        };
        require APP_ROOT . '/views/partials/pagination.php';
      ?>
    <?php endif; ?>
  </section>
</div>
