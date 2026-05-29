<form method="get" action="/admin/tags" class="kanban-search kanban-search--with-submit" style="max-width:360px;margin-bottom:var(--space-8);">
  <i class="fa-solid fa-magnifying-glass"></i>
  <input class="input input--inline" name="q" placeholder="Search tags…" value="<?= e($query ?? '') ?>" data-tag-search>
  <button type="submit" class="kanban-search__submit" aria-label="Search"><i class="fa-solid fa-arrow-right"></i></button>
</form>

<?php
$byScope = [];
foreach ($tags as $t) {
    $byScope[$t['scope']][] = $t;
}
$scopeLabels = ['project' => 'Project tags', 'task' => 'Task tags'];
foreach ($scopeLabels as $scope => $label):
    if (empty($byScope[$scope])) continue;
?>
  <details open style="margin-bottom:32px;">
    <summary style="font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink-3);cursor:pointer;padding:8px 0;border-bottom:1px solid var(--rule);margin-bottom:12px;list-style:none;display:flex;align-items:center;gap:8px;">
      <i class="fa-solid fa-chevron-down" style="font-size:10px;"></i>
      <?= e($label) ?>
      <span style="font-size:11px;font-weight:400;color:var(--ink-3);">(<?= count($byScope[$scope]) ?>)</span>
    </summary>
    <div style="display:flex;flex-direction:column;gap:10px;">
      <?php foreach ($byScope[$scope] as $tag):
        $usage = $usages[(int)$tag['id']] ?? ['project_count' => 0, 'task_count' => 0];
      ?>
        <article class="card" data-tag-id="<?= (int)$tag['id'] ?>" data-tag-row style="flex-direction:row;align-items:center;gap:16px;min-height:auto;padding:12px 16px;">
          <span class="tag-name" contenteditable="true" spellcheck="false"
                data-original="<?= e($tag['name']) ?>"
                data-tag-name
                style="font-weight:600;font-size:14px;flex:1;outline:none;border-bottom:1px dashed transparent;cursor:text;padding-bottom:2px;">
            <?= e($tag['name']) ?>
          </span>
          <label class="color-swatch tag-swatch" title="Change color" style="background: <?= e($tag['color']) ?>; width:32px;">
            <input type="color" class="tag-color-input" value="<?= e($tag['color']) ?>">
          </label>
          <span class="mono" style="font-size:11px;color:var(--ink-3);min-width:90px;text-align:right;">
            P:<?= (int)$usage['project_count'] ?> T:<?= (int)$usage['task_count'] ?>
          </span>
          <button class="btn-danger" data-action="delete-tag" type="button" style="flex-shrink:0;font-size:12px;padding:4px 10px;">Delete</button>
        </article>
      <?php endforeach; ?>
    </div>
  </details>
<?php endforeach; ?>

<?php if (!$tags): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= !empty($query) ? 'No tags match "' . e($query) . '".' : 'No tags yet.' ?></p>
    <?php if (empty($query)): ?>
      <span class="empty-state__sub">Tags are created from project and task pages.</span>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
  $hrefBuilder = function (int $p) use ($query) {
      return '/admin/tags?page=' . $p . (!empty($query) ? '&q=' . urlencode($query) : '');
  };
  require APP_ROOT . '/views/partials/pagination.php';
?>

<script type="module" src="/assets/js/admin-tags.js"></script>
