<?php
// Inputs: $currentTab, $csrfToken, $currentDriver, $plan
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide" data-db-migrate-wizard data-current-driver="<?= e($currentDriver) ?>">
  <h2 class="fz-20 fw-600 u-mb-space-2">
    <?= e(t('compass.db_migrate.heading')) ?>
  </h2>
  <p class="muted" style="font-size:13px;margin:0 0 var(--space-6);">
    <?= e(t('compass.db_migrate.subtitle')) ?>
  </p>

  <?php if ($currentDriver !== 'sqlite'): ?>
    <p style="padding:12px 14px;background:#f3faf4;border:1px solid #c8e6c9;border-radius:6px;">
      <?= e(t('compass.db_migrate.already_on_mysql')) ?>
    </p>
  <?php else: ?>

    <?php if ($plan): ?>
      <div style="margin-bottom:18px;">
        <h3 class="section-strong-label--alt">
          <?= e(t('compass.db_migrate.plan_heading')) ?>
        </h3>
        <p class="muted text-13-mb-10">
          <?= e(t('compass.db_migrate.plan_summary', [
            'tables' => (string)count($plan['tables']),
            'rows'   => (string)$plan['total_rows'],
            'size'   => human_bytes((int)$plan['file_bytes']),
          ])) ?>
        </p>
        <table class="voters-table mt-8">
          <thead>
            <tr>
              <th><?= e(t('compass.db_migrate.col_table')) ?></th>
              <th><?= e(t('compass.db_migrate.col_rows')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plan['tables'] as $row): ?>
              <tr>
                <td style="font-family:var(--font-mono);font-size:13px;"><?= e($row['name']) ?></td>
                <td><?= (int)$row['rows'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <h3 style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.1em;color:var(--ink-3);margin:24px 0 8px;">
      <?= e(t('compass.db_migrate.target_heading')) ?>
    </h3>
    <form data-form="connect" class="form-grid form-grid--2 m-0">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
      <div class="field">
        <label for="f-db-host"><?= e(t('compass.db_migrate.field_host')) ?></label>
        <input id="f-db-host" class="input" type="text" name="host" value="127.0.0.1" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-db-port"><?= e(t('compass.db_migrate.field_port')) ?></label>
        <input id="f-db-port" class="input" type="number" name="port" value="3306">
      </div>
      <div class="field">
        <label for="f-db-name"><?= e(t('compass.db_migrate.field_db')) ?></label>
        <input id="f-db-name" class="input" type="text" name="db" placeholder="otack" autocomplete="off">
      </div>
      <div class="field">
        <label for="f-db-user"><?= e(t('compass.db_migrate.field_user')) ?></label>
        <input id="f-db-user" class="input" type="text" name="user" autocomplete="off">
      </div>
      <div class="field" style="grid-column:1/-1;">
        <label for="f-db-password"><?= e(t('compass.db_migrate.field_password')) ?></label>
        <input id="f-db-password" class="input" type="password" name="password" autocomplete="new-password">
      </div>
    </form>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;">
      <button type="button" class="btn btn--secondary" data-action="test">
        <i class="fa-solid fa-plug-circle-check" aria-hidden="true"></i>
        <?= e(t('compass.db_migrate.test_button')) ?>
      </button>
      <button type="button" class="btn btn--primary" data-action="run"
              data-confirm-title="<?= e(t('compass.db_migrate.confirm_title')) ?>"
              data-confirm-body="<?= e(t('compass.db_migrate.confirm_body')) ?>"
              data-confirm-label="<?= e(t('compass.db_migrate.confirm_label')) ?>"
              data-running-label="<?= e(t('compass.db_migrate.running')) ?>"
              disabled>
        <i class="fa-solid fa-right-left" aria-hidden="true"></i>
        <?= e(t('compass.db_migrate.start_button')) ?>
      </button>
    </div>

    <div data-test-result class="mt-14"></div>

    <div data-run-result style="margin-top:18px;display:none;">
      <h3 class="section-strong-label--alt">
        <?= e(t('compass.db_migrate.next_steps_heading')) ?>
      </h3>
      <p class="muted text-13-mb-10"><?= e(t('compass.db_migrate.next_steps_body')) ?></p>
      <pre data-env-block style="background:var(--paper-3,#0f0f0f0a);padding:12px 14px;border-radius:6px;overflow:auto;font-size:12px;"></pre>
      <div style="margin-top:14px;display:flex;gap:10px;">
        <button type="button" class="btn btn--secondary" data-action="verify">
          <i class="fa-solid fa-check" aria-hidden="true"></i>
          <?= e(t('compass.db_migrate.verify_button')) ?>
        </button>
        <span data-verify-result class="muted" style="font-size:13px;align-self:center;"></span>
      </div>
    </div>

  <?php endif; ?>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass-db-migrate.js')) ?>"></script>
