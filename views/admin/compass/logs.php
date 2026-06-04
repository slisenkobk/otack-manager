<?php
// Inputs: $entries (list of {time, level, body}), $logSize (int), $level (string), $csrfToken
$levels = [
    ''           => t('compass.logs.all_levels'),
    'error'      => t('compass.logs.level.error'),
    'warning'    => t('compass.logs.level.warning'),
    'notice'     => t('compass.logs.level.notice'),
    'deprecated' => t('compass.logs.level.deprecated'),
];
$displayCap = 50; // controller already caps the tail at 100; we show at most 50.
$entries    = array_slice($entries, 0, $displayCap);
?>
<?php include APP_ROOT . '/views/partials/compass-tabs.php'; ?>
<div class="brief brief--wide">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:var(--space-6);margin-bottom:var(--space-6);flex-wrap:wrap;">
    <div>
      <h2 class="fz-20 fw-600 u-mb-space-2"><?= e(t('compass.logs.heading')) ?></h2>
      <p class="muted fz-13 m-0">
        <?= e(t('compass.logs.meta', [
            'size' => human_bytes((int)$logSize),
            'n'    => count($entries),
        ])) ?> <?= e(tn('compass.logs.entries_n', count($entries))) ?>
        <?php if ($level !== ''): ?>· <?= t('compass.logs.filter', ['level' => '<code>' . e($level) . '</code>']) ?><?php endif; ?>
      </p>
    </div>
    <div style="display:flex;align-items:center;gap:var(--space-3);flex-wrap:wrap;">
      <form method="get" action="/admin/compass/logs" style="display:flex;align-items:center;gap:var(--space-3);">
        <label for="compass-level" class="muted fz-13"><?= e(t('compass.logs.level')) ?></label>
        <select id="compass-level" name="level" class="input" style="min-width:160px;" data-compass-auto-submit>
          <?php foreach ($levels as $val => $label): ?>
            <option value="<?= e($val) ?>"<?= $level === $val ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="btn btn--danger btn--sm" type="button"
              data-compass-action="clear-error-log"
              data-compass-confirm="<?= e(t('compass.logs.clear.confirm')) ?>"
              data-compass-confirm-label="<?= e(t('compass.logs.clear')) ?>"
              <?= (int)$logSize === 0 ? 'disabled' : '' ?>>
        <i class="fa-solid fa-trash mr-space-2" aria-hidden="true"></i>
        <?= e(t('compass.logs.clear')) ?>
      </button>
    </div>
  </div>

  <?php if (!$entries): ?>
    <p class="muted" style="padding:var(--space-12) 0;text-align:center;"><?= e(t('compass.logs.empty')) ?></p>
  <?php else: ?>
    <ol style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:var(--space-3);">
    <?php foreach ($entries as $entry):
      $lvlLower = strtolower($entry['level']);
      $isError = str_contains($lvlLower, 'error') || str_contains($lvlLower, 'fatal');
      $tagColor = $isError ? 'var(--accent)' : 'var(--text-2)';
    ?>
      <li style="border:1px solid var(--rule);border-radius:var(--radius-sm);overflow:hidden;background:var(--paper);">
        <header style="display:flex;justify-content:space-between;align-items:baseline;padding:var(--space-3) var(--space-4);background:var(--paper-2);border-bottom:1px solid var(--rule);">
          <span style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;color:<?= e($tagColor) ?>;"><?= e($entry['level']) ?></span>
          <span class="muted" style="font-size:11px;font-family:var(--font-mono);"><?= e($entry['time']) ?></span>
        </header>
        <pre style="margin:0;padding:var(--space-4);font-family:var(--font-mono);font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-all;color:var(--text-2);"><?= e(rtrim($entry['body'])) ?></pre>
      </li>
    <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>

<script type="module" src="<?= e(asset_url('/assets/js/compass.js')) ?>"></script>
