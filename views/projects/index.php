<div class="section-head">
  <div class="num">01</div>
  <div class="title">Your <span style="color:var(--brand);font-weight:600;">projects</span></div>
  <div class="rule"></div>
  <div style="display:flex;gap:var(--space-6);align-items:center;">
    <?php if ($projects): ?>
      <div class="kanban-search" style="margin:0;">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input class="input input--inline" placeholder="Search projects…" data-project-search>
      </div>
    <?php endif; ?>
    <a href="/projects/new" class="btn btn--secondary" style="text-decoration:none;">+ New project</a>
  </div>
</div>

<?php if (!$projects): ?>
  <div class="brief" style="text-align:center;">
    <p style="margin:0;color:var(--ink-2);">No projects yet. <a href="/projects/new" style="color:var(--brand);">Create your first one</a>.</p>
  </div>
<?php else: ?>
  <div class="cards-row">
    <?php foreach ($projects as $i => $p): ?>
      <a class="card" href="/projects/<?= (int)$p['id'] ?>" style="text-decoration:none;color:inherit;">
        <span class="corner-tag">P · <?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
        <span class="corner-meta"><?= fmt_date($p['updated_at']) ?></span>
        <div class="card-head">
          <div class="ini"><?= e(mb_strtoupper(mb_substr($p['name'], 0, 2))) ?></div>
          <div>
            <h3 class="name"><?= e($p['name']) ?></h3>
          </div>
        </div>
        <div class="card-row">
          <span class="status<?= $p['status'] === 'active' ? ' is-ready' : '' ?>"><?= e($p['status']) ?></span>
          <span class="share-link">Open <span class="arr">&#8594;</span></span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script type="module" src="/assets/js/projects.js"></script>
