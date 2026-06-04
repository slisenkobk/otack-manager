<?php
// Inputs: $sessions{total,stale,stale_bytes,lifetime_seconds},
//         $uploads{total,orphan,orphan_bytes},
//         $assetVersion, $csrfToken
$hours = (int)round($sessions['lifetime_seconds'] / 3600);
$card = 'background:var(--paper);border:1px solid var(--rule);border-radius:var(--radius);padding:var(--space-8);display:flex;flex-direction:column;gap:var(--space-4);';
$cardTitle = 'font-size:15px;font-weight:600;margin:0;display:flex;align-items:center;gap:var(--space-3);';
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:var(--space-6);">

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-clock-rotate-left text-text-2" aria-hidden="true"></i>
        <?= e(t('compass.cache.sessions')) ?>
      </h3>
      <p class="m-0 fz-14">
        <?= t('compass.cache.sessions.stats', [
            'total' => '<strong>' . (int)$sessions['total'] . '</strong>',
            'stale' => '<strong style="color:' . ($sessions['stale'] > 0 ? 'var(--accent)' : 'var(--text-2)') . ';">' . (int)$sessions['stale'] . '</strong>',
            'bytes' => '<span class="muted">' . e(human_bytes((int)$sessions['stale_bytes'])) . '</span>',
        ]) ?>
      </p>
      <p class="muted fz-12 m-0"><?= e(t('compass.cache.sessions.hint', ['hours' => $hours])) ?></p>
      <div>
        <button class="btn btn--danger" type="button"
                data-compass-action="clear-sessions"
                data-compass-confirm="<?= e(t('compass.cache.sessions.confirm', ['n' => (int)$sessions['stale']])) ?>"
                data-compass-confirm-label="<?= e(t('common.delete')) ?>"
                <?= $sessions['stale'] === 0 ? 'disabled' : '' ?>>
          <?= e(t('compass.cache.sessions.clear')) ?>
        </button>
      </div>
    </section>

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-folder-open text-text-2" aria-hidden="true"></i>
        <?= e(t('compass.cache.uploads')) ?>
      </h3>
      <p class="m-0 fz-14">
        <?= t('compass.cache.uploads.stats', [
            'total'  => '<strong>' . (int)$uploads['total'] . '</strong>',
            'orphan' => '<strong style="color:' . ($uploads['orphan'] > 0 ? 'var(--accent)' : 'var(--text-2)') . ';">' . (int)$uploads['orphan'] . '</strong>',
            'bytes'  => '<span class="muted">' . e(human_bytes((int)$uploads['orphan_bytes'])) . '</span>',
        ]) ?>
      </p>
      <p class="muted fz-12 m-0">
        <?= t('compass.cache.uploads.hint', ['path' => '<code>public/uploads/</code>', 'table' => '<code>attachments</code>']) ?>
      </p>
      <div>
        <button class="btn btn--danger" type="button"
                data-compass-action="clear-uploads-orphans"
                data-compass-confirm="<?= e(t('compass.cache.uploads.confirm', ['n' => (int)$uploads['orphan']])) ?>"
                data-compass-confirm-label="<?= e(t('common.delete')) ?>"
                <?= $uploads['orphan'] === 0 ? 'disabled' : '' ?>>
          <?= e(t('compass.cache.uploads.clear')) ?>
        </button>
      </div>
    </section>

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-list-ul text-text-2" aria-hidden="true"></i>
        <?= e(t('compass.cache.activity_log')) ?>
      </h3>
      <p class="m-0 fz-14">
        <?= t('compass.cache.activity_log.stats', [
            'total' => '<strong>' . (int)$activity['total'] . '</strong>',
            'stale' => '<strong style="color:' . ($activity['stale'] > 0 ? 'var(--accent)' : 'var(--text-2)') . ';">' . (int)$activity['stale'] . '</strong>',
            'days'  => (int)$activity['keep_days'],
        ]) ?>
      </p>
      <p class="muted fz-12 m-0">
        <?= e(t('compass.cache.activity_log.hint', ['days' => (int)$activity['keep_days']])) ?>
      </p>
      <div>
        <button class="btn btn--danger" type="button"
                data-compass-action="prune-activity-log"
                data-compass-confirm="<?= e(t('compass.cache.activity_log.confirm', ['n' => (int)$activity['stale']])) ?>"
                data-compass-confirm-label="<?= e(t('common.delete')) ?>"
                <?= $activity['stale'] === 0 ? 'disabled' : '' ?>>
          <?= e(t('compass.cache.activity_log.prune')) ?>
        </button>
      </div>
    </section>

    <section style="<?= e($card) ?>">
      <h3 style="<?= e($cardTitle) ?>">
        <i class="fa-solid fa-rotate text-text-2" aria-hidden="true"></i>
        <?= e(t('compass.cache.assets')) ?>
      </h3>
      <p class="m-0 fz-14">
        <?= t('compass.cache.assets.current', [
            'version' => $assetVersion !== ''
                ? '<strong><code>' . e($assetVersion) . '</code></strong>'
                : '<strong><span class="muted">' . e(t('compass.cache.assets.none')) . '</span></strong>',
        ]) ?>
      </p>
      <p class="muted fz-12 m-0">
        <?= e(t('compass.cache.assets.hint')) ?>
      </p>
      <div>
        <button class="btn btn--primary" type="button"
                data-compass-action="bust-asset-cache"
                data-compass-confirm="<?= e(t('compass.cache.assets.confirm')) ?>"
                data-compass-confirm-label="<?= e(t('compass.cache.assets.bump')) ?>">
          <?= e(t('compass.cache.assets.bust')) ?>
        </button>
      </div>
    </section>

  </div>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
