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
      <button type="button" class="btn btn--secondary" data-action="check-now">
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
</section>

<script type="module" src="<?= e(asset_url('/assets/js/updates-tab.js')) ?>"></script>
