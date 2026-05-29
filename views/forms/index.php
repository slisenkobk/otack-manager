<div class="page-actions" style="margin-bottom:var(--space-8);justify-content:flex-end;">
  <a href="/forms/new" class="btn btn--secondary" style="text-decoration:none;">+ New form</a>
</div>

<?php if (!$forms): ?>
  <div class="empty-state">
    <span class="empty-state__tag">Otack Manager</span>
    <p class="empty-state__text">No forms yet. <a href="/forms/new">Build your first one</a>.</p>
  </div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:12px;">
    <?php foreach ($forms as $f):
      $publicUrl = \abs_url('/f/' . $f['hash']);
    ?>
      <a class="card" href="/forms/<?= (int)$f['id'] ?>" style="text-decoration:none;color:inherit;flex-direction:row;align-items:center;gap:18px;min-height:auto;" data-form-id="<?= (int)$f['id'] ?>">
        <span class="corner-tag">F-<?= (int)$f['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($f['created_at']) ?></span>
        <div style="flex:1;min-width:0;padding-top:14px;">
          <h3 class="name" style="margin:0 0 4px;font-size:16px;font-weight:600;"><?= e($f['title']) ?></h3>
          <?php
            // Description is HTML — strip for the card preview
            $descPreview = trim(strip_tags((string)$f['description']));
          ?>
          <?php if ($descPreview !== ''): ?>
            <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 6px;line-height:1.35;"><?= e(mb_strimwidth($descPreview, 0, 160, '…')) ?></p>
          <?php endif; ?>
          <div style="font-family:var(--font-mono);font-size:11px;color:var(--ink-3);display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span data-link-host style="color:var(--accent);"><i class="fa-solid fa-link"></i> <?= e($publicUrl) ?></span>
            <button type="button" class="btn-ghost" data-action="copy-link" data-url="<?= e($publicUrl) ?>" data-stop style="font-size:11px;">Copy</button>
            <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn-ghost" data-stop style="font-size:11px;text-decoration:none;">Open ↗</a>
          </div>
        </div>
        <button class="btn-danger" type="button" data-action="delete-form" data-stop title="Delete"><i class="fa-solid fa-trash"></i></button>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/forms-index.js"></script>
