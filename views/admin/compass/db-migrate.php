<?php
// Inputs: $currentTab, $csrfToken, $currentDriver, $plan
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide" data-db-migrate-wizard data-current-driver="<?= e($currentDriver) ?>">
  <h2 class="fz-20 fw-600 u-mb-space-2">
    <?= e(t('compass.db_migrate.heading')) ?>
  </h2>
  <p class="muted db-migrate-subtitle">
    <?= e(t('compass.db_migrate.subtitle')) ?>
  </p>

  <?php if ($currentDriver !== 'sqlite'): ?>
    <p class="db-migrate-callout">
      <?= e(t('compass.db_migrate.already_on_mysql')) ?>
    </p>
  <?php else: ?>

    <?php if ($plan): ?>
      <div class="db-migrate-plan">
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
        <div class="data-table-wrap mt-8">
          <table class="data-table">
            <thead>
              <tr class="table-head-row">
                <th class="table-cell--strong"><?= e(t('compass.db_migrate.col_table')) ?></th>
                <th class="table-cell--strong-right"><?= e(t('compass.db_migrate.col_rows')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($plan['tables'] as $row): ?>
                <tr class="border-rule-bottom">
                  <td><code class="fz-12 table-cell"><?= e($row['name']) ?></code></td>
                  <td class="table-cell--num-right"><?= number_format((int)$row['rows']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <h3 class="db-migrate-target-heading">
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
      <div class="field field--full">
        <label for="f-db-password"><?= e(t('compass.db_migrate.field_password')) ?></label>
        <input id="f-db-password" class="input" type="password" name="password" autocomplete="new-password">
      </div>
    </form>

    <div class="db-migrate-actions">
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

    <div data-run-result class="db-migrate-run-result">
      <h3 class="section-strong-label--alt">
        <?= e(t('compass.db_migrate.next_steps_heading')) ?>
      </h3>
      <p class="muted text-13-mb-10"><?= e(t('compass.db_migrate.next_steps_body')) ?></p>
      <pre data-env-block class="db-migrate-env-block"></pre>
      <div class="db-migrate-verify-row">
        <button type="button" class="btn btn--secondary" data-action="verify">
          <i class="fa-solid fa-check" aria-hidden="true"></i>
          <?= e(t('compass.db_migrate.verify_button')) ?>
        </button>
        <span data-verify-result class="muted db-migrate-verify-result"></span>
      </div>
    </div>

  <?php endif; ?>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass-db-migrate.js')) ?>"></script>
