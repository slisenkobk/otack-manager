<?php
// Inputs: $updates (Updater::cachedPayload), $currentVersion (latest app_versions row), $csrfToken.
// Step 2 surface: Current + Check-for-updates only. History + Backups arrive in step 3.
$current     = $updates['current']    ?? '0.0.0';
$available   = $updates['available']  ?? null;
$hasUpdate   = !empty($updates['has_update']);
$checkedAt   = $updates['checked_at'] ?? null;
$installedAt = $currentVersion['installed_at'] ?? null;
?>
<section class="updates-section" data-updates-section>
  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.current')) ?></h3>
    <p style="margin:0;font-size:18px;">
      <strong><?= e(app_name()) ?></strong>
      <span class="updates-version">v<?= e($current) ?></span>
    </p>
    <?php if ($installedAt): ?>
      <p class="muted" style="font-size:12px;color:var(--ink-3);margin:6px 0 0;">
        <?= e(t('updates.installed_at', ['when' => fmt_datetime($installedAt)])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.check')) ?></h3>
    <p style="margin:0;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span class="muted" data-last-check-label>
        <?php if ($checkedAt): ?>
          <?= e(t('updates.last_check', ['when' => fmt_datetime(date('c', $checkedAt))])) ?>
        <?php else: ?>
          <?= e(t('updates.last_check_never')) ?>
        <?php endif; ?>
      </span>
      <button type="button" class="btn btn--secondary" data-action="check-now"
              data-toast-ok="<?= e(t('updates.toast.check_ok')) ?>"
              data-toast-error="<?= e(t('updates.toast.check_error')) ?>">
        <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
        <?= e(t('updates.check_now')) ?>
      </button>
    </p>

    <div data-update-status style="margin-top:14px;">
      <?php if ($hasUpdate): ?>
        <p style="margin:0;">
          <span class="poll-status poll-status--active" style="background:#e6f4ea;color:#1e6f3a;">
            <?= e(t('updates.available_label', ['version' => 'v' . $available])) ?>
          </span>
        </p>
        <p class="muted" style="font-size:12px;color:var(--ink-3);margin:8px 0 0;">
          <?= e(t('updates.available_hint')) ?>
        </p>
      <?php elseif ($available !== null && $available === $current): ?>
        <p class="muted" style="margin:0;font-size:13px;"><?= e(t('updates.up_to_date')) ?></p>
      <?php elseif ($checkedAt === null): ?>
        <p class="muted" style="margin:0;font-size:13px;"><?= e(t('updates.no_check_yet')) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($hasUpdate): ?>
    <?php
      $lastDuration = (int)($updates['last_duration_seconds'] ?? 0);
      $durationHint = $lastDuration > 0
        ? t('updates.duration_with_previous', ['seconds' => (string)$lastDuration])
        : t('updates.duration_default');
    ?>
    <div class="updates-card updates-card--accent">
      <h3 class="updates-card__title"><?= e(t('updates.section.update_available')) ?></h3>
      <p style="margin:0 0 8px;font-size:15px;">
        <?= e(t('updates.update_intro', [
          'from' => 'v' . $current,
          'to'   => 'v' . $available,
        ])) ?>
      </p>
      <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 14px;">
        <?= e($durationHint) ?>
      </p>
      <form method="post" action="/admin/updates/run" data-update-form>
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <button type="button" class="btn btn--primary" data-action="run-update"
                data-confirm-title="<?= e(t('updates.confirm.title')) ?>"
                data-confirm-body="<?= e(t('updates.confirm.body', [
                  'from' => 'v' . $current,
                  'to'   => 'v' . $available,
                ])) ?>"
                data-confirm-label="<?= e(t('updates.confirm.confirm')) ?>"
                data-cancel-label="<?= e(t('updates.confirm.cancel')) ?>"
                data-running-label="<?= e(t('updates.running')) ?>">
          <i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i>
          <?= e(t('updates.update_now', ['version' => 'v' . $available])) ?>
        </button>
      </form>
    </div>
  <?php endif; ?>

  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.history')) ?></h3>
    <?php if (!$versionHistory): ?>
      <p class="muted" style="margin:0;font-size:13px;"><?= e(t('updates.history_empty')) ?></p>
    <?php else: ?>
      <table class="voters-table">
        <thead>
          <tr>
            <th><?= e(t('updates.history_col_version')) ?></th>
            <th><?= e(t('updates.history_col_source')) ?></th>
            <th><?= e(t('updates.history_col_when')) ?></th>
            <th><?= e(t('updates.history_col_by')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($versionHistory as $v): ?>
            <tr>
              <td><span class="updates-version">v<?= e((string)$v['version']) ?></span></td>
              <td><?= e(t('updates.source.' . (string)$v['source'])) ?></td>
              <td class="voters-table__when"><?= e(fmt_datetime($v['installed_at'])) ?></td>
              <td><?= e($v['applied_by_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.backups')) ?></h3>
    <?php if (!$backups): ?>
      <p class="muted" style="margin:0;font-size:13px;"><?= e(t('updates.backups_empty')) ?></p>
    <?php else: ?>
      <table class="voters-table">
        <thead>
          <tr>
            <th><?= e(t('updates.backups_col_versions')) ?></th>
            <th><?= e(t('updates.backups_col_when')) ?></th>
            <th><?= e(t('updates.backups_col_size')) ?></th>
            <th><?= e(t('updates.backups_col_kind')) ?></th>
            <th><?= e(t('updates.backups_col_action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($backups as $b): ?>
            <?php $isPruned = !empty($b['pruned_at']); ?>
            <tr<?= $isPruned ? ' style="opacity:0.5;"' : '' ?>>
              <td>
                <span class="updates-version">v<?= e((string)$b['version_from']) ?></span>
                → <span class="updates-version">v<?= e((string)$b['version_to']) ?></span>
              </td>
              <td class="voters-table__when"><?= e(fmt_datetime($b['created_at'])) ?></td>
              <td><?= e(fmt_size((int)$b['size_bytes'])) ?></td>
              <td><?= e(t('updates.kind.' . (string)$b['kind'])) ?></td>
              <td>
                <?php if ($isPruned): ?>
                  <span class="muted" style="font-size:12px;"><?= e(t('updates.backup_pruned')) ?></span>
                <?php else: ?>
                  <span class="muted" style="font-size:12px;"><?= e(t('updates.backup_restore_soon')) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<script type="module" src="<?= e(asset_url('/assets/js/updates-tab.js')) ?>"></script>
