<div class="page-actions">
  <?php if ($projects): ?>
    <div class="kanban-search" style="margin:0;">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input class="input input--inline" placeholder="Search projects…" data-project-search>
    </div>
  <?php else: ?>
    <span></span>
  <?php endif; ?>
  <a href="/projects/new" class="btn btn--secondary" style="text-decoration:none;">+ New project</a>
</div>

<?php if (!$projects): ?>
  <div class="brief" style="text-align:center;">
    <p style="margin:0;color:var(--ink-2);">No projects yet. <a href="/projects/new" style="color:var(--brand);">Create your first one</a>.</p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($projects as $i => $p): ?>
      <a class="card" href="/projects/<?= (int)$p['id'] ?>" style="text-decoration:none;color:inherit;">
        <span class="corner-tag">P-<?= (int)$p['id'] ?></span>
        <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
        <div class="card-head">
          <div class="ini"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
          <div>
            <h3 class="name"><?= e($p['name']) ?></h3>
          </div>
        </div>
        <?php $desc = trim(strip_tags((string)($p['description'] ?? ''))); ?>
        <?php if ($desc !== ''): ?>
          <p class="card__desc"><?= e($desc) ?></p>
        <?php endif; ?>
        <div class="card-row">
          <span class="status<?= $p['status'] === 'active' ? ' is-ready' : '' ?>"><?= e($p['status']) ?></span>
          <span class="share-link">Open <span class="arr">&#8594;</span></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/projects.js"></script>
