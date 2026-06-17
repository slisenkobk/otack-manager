<?php // Inputs: page, versions, csrfToken, canEdit ?>

<div class="page-actions">
  <a class="btn btn--ghost knowledge-back" href="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>">
    <i class="fa-solid fa-arrow-left"></i> <?= e($page['title']) ?>
  </a>
  <?php if (!empty($canEdit)): ?>
    <div class="page-actions__right">
      <form method="post" action="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/snapshot" class="knowledge-inline-form">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <button type="submit" class="btn btn--primary" data-action="snapshot">
          <i class="fa-solid fa-camera"></i> <?= e(t('knowledge.snapshot.save')) ?>
        </button>
      </form>
    </div>
  <?php endif; ?>
</div>

<header class="u-mt-space-6">
  <h2 class="fz-20 fw-600 u-mb-space-2">
    <?= e(t('knowledge.versions_for', ['title' => (string)$page['title']])) ?>
  </h2>
  <p class="muted fz-13 m-0">
    <?= e(t('knowledge.versions.lede')) ?>
  </p>
</header>

<?php if (!$versions): ?>
  <div class="empty-state u-mt-space-6">
    <p class="empty-state__text"><?= e(t('knowledge.versions.empty')) ?></p>
  </div>
<?php else: ?>
  <div class="data-table-wrap u-mt-space-6">
    <table class="data-table">
      <thead>
        <tr>
          <th class="table-cell--strong"><?= e(t('knowledge.versions.col_id')) ?></th>
          <th class="table-cell--strong"><?= e(t('knowledge.versions.col_note')) ?></th>
          <th class="table-cell--strong"><?= e(t('knowledge.versions.col_when')) ?></th>
          <th class="table-cell--strong"><?= e(t('knowledge.versions.col_by')) ?></th>
          <th class="data-table__cell--actions"><?= e(t('common.actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($versions as $v): ?>
          <tr>
            <td><code class="fz-12 table-cell">#<?= (int)$v['id'] ?></code></td>
            <td><?= e((string)($v['note'] ?? '') !== '' ? (string)$v['note'] : '—') ?></td>
            <td class="data-table__meta-time"><?= e(fmt_datetime_full($v['snapshot_at'])) ?></td>
            <td><?= e((string)($v['snapshot_by_name'] ?? '—')) ?></td>
            <td class="data-table__cell--actions">
              <a class="btn btn--ghost btn--sm"
                 href="/knowledge/<?= e(rawurlencode((string)$page['slug'])) ?>/versions/<?= (int)$v['id'] ?>">
                <i class="fa-solid fa-eye"></i> <?= e(t('common.open')) ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
