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
    <p class="updates-current-line">
      <strong><?= e(app_name()) ?></strong>
      <span class="updates-version">v<?= e($current) ?></span>
    </p>
    <?php if ($installedAt): ?>
      <p class="muted field-hint--tight">
        <?= e(t('updates.installed_at', ['when' => fmt_datetime($installedAt)])) ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.check')) ?></h3>
    <p class="updates-check-line">
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

    <div data-update-status class="mt-14">
      <?php if ($hasUpdate): ?>
        <p class="m-0">
          <span class="poll-status poll-status--active">
            <?= e(t('updates.available_label', ['version' => 'v' . $available])) ?>
          </span>
        </p>
        <p class="muted field-hint--tight2">
          <?= e(t('updates.available_hint')) ?>
        </p>
      <?php elseif ($available !== null && $available === $current): ?>
        <p class="muted m-0 fz-13"><?= e(t('updates.up_to_date')) ?></p>
      <?php elseif ($checkedAt === null): ?>
        <p class="muted m-0 fz-13"><?= e(t('updates.no_check_yet')) ?></p>
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
      <p class="updates-intro">
        <?= e(t('updates.update_intro', [
          'from' => 'v' . $current,
          'to'   => 'v' . $available,
        ])) ?>
      </p>
      <p class="muted updates-duration">
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
      <p class="muted m-0 fz-13"><?= e(t('updates.history_empty')) ?></p>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="table-cell--strong"><?= e(t('updates.history_col_version')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.history_col_source')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.history_col_when')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.history_col_by')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($versionHistory as $v): ?>
              <tr>
                <td><span class="updates-version">v<?= e((string)$v['version']) ?></span></td>
                <td><?= e(t('updates.source.' . (string)$v['source'])) ?></td>
                <td class="data-table__meta-time"><?= e(fmt_datetime_full($v['installed_at'])) ?></td>
                <td><?= e($v['applied_by_name'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="updates-card">
    <h3 class="updates-card__title"><?= e(t('updates.section.backups')) ?></h3>
    <?php if (!$backups): ?>
      <p class="muted m-0 fz-13"><?= e(t('updates.backups_empty')) ?></p>
    <?php else: ?>
      <div class="data-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="table-cell--strong"><?= e(t('updates.backups_col_versions')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.backups_col_when')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.backups_col_size')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.backups_col_kind')) ?></th>
              <th class="table-cell--strong"><?= e(t('updates.backups_col_action')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($backups as $b): ?>
              <?php $isPruned = !empty($b['pruned_at']); ?>
              <tr class="opacity-50"<?= $isPruned ? '' : '' ?>>
                <td>
                  <span class="updates-version">v<?= e((string)$b['version_from']) ?></span>
                  → <span class="updates-version">v<?= e((string)$b['version_to']) ?></span>
                </td>
                <td class="data-table__meta-time"><?= e(fmt_datetime_full($b['created_at'])) ?></td>
              <td><?= e(fmt_size((int)$b['size_bytes'])) ?></td>
              <td><?= e(t('updates.kind.' . (string)$b['kind'])) ?></td>
              <td>
                <?php if ($isPruned): ?>
                  <span class="muted fz-12"><?= e(t('updates.backup_pruned')) ?></span>
                <?php else: ?>
                  <form method="post"
                        action="/admin/updates/restore/<?= (int)$b['id'] ?>"
                        data-restore-form
                        class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <button type="button" class="btn btn--ghost btn--sm" data-action="run-restore"
                            data-confirm-title="<?= e(t('updates.restore.confirm_title')) ?>"
                            data-confirm-body="<?= e(t('updates.restore.confirm_body', [
                              'from' => 'v' . (string)$b['version_to'],
                              'to'   => 'v' . (string)$b['version_from'],
                            ])) ?>"
                            data-confirm-label="<?= e(t('updates.restore.confirm_label')) ?>"
                            data-cancel-label="<?= e(t('updates.confirm.cancel')) ?>"
                            data-running-label="<?= e(t('updates.restore.running')) ?>">
                      <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                      <?= e(t('updates.restore.button')) ?>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<script type="module" src="<?= e(asset_url('/assets/js/updates-tab.js')) ?>"></script>
