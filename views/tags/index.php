<form method="get" action="/admin/tags" class="kanban-search kanban-search--with-submit tags-search">
  <i class="fa-solid fa-magnifying-glass"></i>
  <input class="input input--inline" name="q" placeholder="<?= e(t('tags.search_placeholder')) ?>" value="<?= e($query ?? '') ?>" data-tag-search>
  <button type="submit" class="kanban-search__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
</form>

<?php
$byScope = [];
foreach ($tags as $tg) {
    $byScope[$tg['scope']][] = $tg;
}
$scopeLabels = ['project' => t('tags.project_tags'), 'task' => t('tags.task_tags')];
foreach ($scopeLabels as $scope => $label):
    if (empty($byScope[$scope])) continue;
?>
  <details open class="tag-group">
    <summary class="tag-group__title">
      <i class="fa-solid fa-chevron-down tag-group__chevron"></i>
      <?= e($label) ?>
      <span class="tag-group__count">(<?= count($byScope[$scope]) ?>)</span>
    </summary>
    <div class="tag-chip-row">
      <?php foreach ($byScope[$scope] as $tag):
        $usage = $usages[(int)$tag['id']] ?? ['project_count' => 0, 'task_count' => 0];
      ?>
        <article class="card tag-chip" data-tag-id="<?= (int)$tag['id'] ?>" data-tag-row>
          <label class="color-swatch tag-swatch tag-chip__swatch" title="<?= e(t('tags.change_color')) ?>" data-bg="<?= e($tag['color']) ?>">
            <input type="color" class="tag-color-input" value="<?= e($tag['color']) ?>">
          </label>
          <span class="tag-name tag-chip__name" contenteditable="true" spellcheck="false"
                data-original="<?= e($tag['name']) ?>"
                data-tag-name>
            <?= e($tag['name']) ?>
          </span>
          <span class="tag-chip__usage mono">
            P:<?= (int)$usage['project_count'] ?> T:<?= (int)$usage['task_count'] ?>
          </span>
          <button class="btn btn--danger tag-chip__delete" data-action="delete-tag" type="button" aria-label="<?= e(t('common.delete')) ?>">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </article>
      <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>

<?php if (!$tags): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= !empty($query) ? e(t('tags.empty.match', ['q' => $query])) : e(t('tags.empty.none')) ?></p>
    <?php if (empty($query)): ?>
      <span class="empty-state__sub"><?= e(t('tags.created_from_hint')) ?></span>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
  $hrefBuilder = function (int $p) use ($query) {
      return '/admin/tags?page=' . $p . (!empty($query) ? '&q=' . urlencode($query) : '');
  };
  require APP_ROOT . '/views/partials/pagination.php';
?>

<script type="module" src="<?= e(asset_url('/assets/js/admin-tags.js')) ?>"></script>
