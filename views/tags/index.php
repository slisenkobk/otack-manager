<div class="section-head">
  <div class="num">01</div>
  <div class="title">All <span style="color:var(--brand);font-weight:600;">tags</span></div>
  <div class="rule"></div>
  <div class="meta"><?= count($tags) ?> total</div>
</div>

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
        <article class="card" data-tag-id="<?= (int)$tag['id'] ?>" style="flex-direction:row;align-items:center;gap:16px;min-height:auto;padding:12px 16px;">
          <span class="tag-swatch" style="width:18px;height:18px;border-radius:3px;background:<?= e($tag['color']) ?>;flex-shrink:0;border:1px solid var(--rule);"></span>
          <span class="tag-name" contenteditable="true" spellcheck="false"
                data-original="<?= e($tag['name']) ?>"
                style="font-weight:600;font-size:14px;flex:1;outline:none;border-bottom:1px dashed transparent;cursor:text;padding-bottom:2px;">
            <?= e($tag['name']) ?>
          </span>
          <input type="color" class="tag-color-input" value="<?= e($tag['color']) ?>"
                 title="Change color"
                 style="width:32px;height:28px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;">
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
  <p style="color:var(--ink-3);font-size:14px;">No tags yet. Tags are created from project and task pages.</p>
<?php endif; ?>

<script type="module" src="/assets/js/admin-tags.js"></script>
