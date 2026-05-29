<?php
// Inputs: $page (int), $pages (int), $hrefBuilder (callable: (int $page) => string)
if (($pages ?? 1) <= 1) return;
$prev = max(1, $page - 1);
$next = min($pages, $page + 1);
?>
<nav class="pager" aria-label="<?= e(t('common.pagination')) ?>">
  <a class="pager__btn<?= $page <= 1 ? ' is-disabled' : '' ?>" <?= $page > 1 ? 'href="' . e($hrefBuilder($prev)) . '"' : '' ?>>
    <i class="fa-solid fa-chevron-left"></i>
  </a>
  <?php
  $window = 2;
  $shown  = [];
  for ($i = 1; $i <= $pages; $i++) {
      if ($i === 1 || $i === $pages || ($i >= $page - $window && $i <= $page + $window)) {
          $shown[] = $i;
      }
  }
  $prevShown = 0;
  foreach ($shown as $i):
      if ($prevShown && $i - $prevShown > 1) echo '<span class="pager__gap">…</span>';
  ?>
    <a class="pager__page<?= $i === $page ? ' is-current' : '' ?>" href="<?= e($hrefBuilder($i)) ?>"><?= $i ?></a>
  <?php $prevShown = $i; endforeach; ?>
  <a class="pager__btn<?= $page >= $pages ? ' is-disabled' : '' ?>" <?= $page < $pages ? 'href="' . e($hrefBuilder($next)) . '"' : '' ?>>
    <i class="fa-solid fa-chevron-right"></i>
  </a>
</nav>
