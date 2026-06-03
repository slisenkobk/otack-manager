<?php
// API tokens — the user's personal-access tokens for the external /api/v1
// surface. Plaintext is only available right after creation (one-time
// reveal); we store SHA-256 hashes server-side.
echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';

$now = time();
function _token_status(array $t, int $now): string {
    if (!empty($t['revoked_at'])) return 'revoked';
    if (!empty($t['expires_at']) && (int)$t['expires_at'] <= $now) return 'expired';
    return 'active';
}
function _token_epoch_fmt(?int $ts): string {
    if (!$ts) return '';
    try {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(app_timezone())
            ->format('d.m.Y H:i');
    } catch (\Throwable $e) { return ''; }
}
?>

<?php if (!empty($oneTime) && !empty($oneTime['token'])): ?>
  <section class="brief brief--wide profile-card api-tokens-reveal" data-token-reveal>
    <h2 class="profile-card__title"><?= e(t('api_tokens.created_once')) ?></h2>
    <p class="muted profile-card__hint">
      <?= e(t('api_tokens.copy_now')) ?>
      <?php if (!empty($oneTime['name'])): ?>
        — <strong><?= e($oneTime['name']) ?></strong>
      <?php endif; ?>
    </p>
    <pre class="copyable api-tokens-reveal__code" data-copy="<?= e($oneTime['token']) ?>" title="<?= e(t('api_tokens.copy_now')) ?>"><?= e($oneTime['token']) ?></pre>
  </section>
<?php endif; ?>

<section class="brief brief--wide profile-card">
  <h2 class="profile-card__title"><?= e(t('api_tokens.create')) ?></h2>
  <form method="post" action="/profile/tokens" class="profile-form" style="margin-top:var(--space-6);">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
    <div class="form-grid form-grid--2">
      <div class="field">
        <label><?= e(t('api_tokens.col_name')) ?></label>
        <input class="input" type="text" name="name" required
               placeholder="<?= e(t('api_tokens.placeholder_name')) ?>">
      </div>
      <div class="field">
        <label><?= e(t('api_tokens.col_expires')) ?></label>
        <input class="input" type="text" name="expires_at"
               placeholder="<?= e(t('api_tokens.placeholder_expires')) ?>">
      </div>
    </div>
    <div class="profile-form__actions" style="margin-top:var(--space-8);">
      <button class="btn btn--primary submit" type="submit">
        <i class="fa-solid fa-plus"></i> <?= e(t('api_tokens.create')) ?>
      </button>
    </div>
  </form>
</section>

<section class="brief brief--wide profile-card">
  <h2 class="profile-card__title"><?= e(t('api_tokens.title')) ?></h2>

  <?php if (!$tokens): ?>
    <div class="empty-state" style="margin-top:var(--space-6);">
      <p class="empty-state__text"><?= e(t('api_tokens.empty_state')) ?></p>
    </div>
  <?php else: ?>
    <div class="data-table-wrap" style="margin-top:var(--space-6);">
      <table class="data-table">
        <thead>
          <tr>
            <th><?= e(t('api_tokens.col_name')) ?></th>
            <th><?= e(t('api_tokens.col_prefix')) ?></th>
            <th><?= e(t('api_tokens.col_created')) ?></th>
            <th><?= e(t('api_tokens.col_last_used')) ?></th>
            <th><?= e(t('api_tokens.col_expires')) ?></th>
            <th><?= e(t('api_tokens.col_status')) ?></th>
            <th class="data-table__cell--actions"><?= e(t('common.actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tokens as $tok): ?>
            <?php
              $status = _token_status($tok, $now);
              $statusKey = 'api_tokens.status_' . $status;
              $statusCls = 'status status--' . $status . ($status === 'active' ? ' is-ready' : '');
            ?>
            <tr data-token-row data-token-id="<?= (int)$tok['id'] ?>">
              <td data-token-name><?= e($tok['name']) ?></td>
              <td>
                <span class="mono" style="font-size:11px;color:var(--ink-3);"><?= e($tok['prefix']) ?>…</span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e(_token_epoch_fmt((int)$tok['created_at'])) ?></span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e(_token_epoch_fmt(isset($tok['last_used_at']) ? (int)$tok['last_used_at'] : null)) ?></span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e(_token_epoch_fmt(isset($tok['expires_at']) ? (int)$tok['expires_at'] : null)) ?></span>
              </td>
              <td>
                <span class="<?= e($statusCls) ?>" data-token-status><?= e(t($statusKey)) ?></span>
              </td>
              <td class="data-table__cell--actions">
                <?php if ($status === 'active'): ?>
                  <form method="post"
                        action="/profile/tokens/<?= (int)$tok['id'] ?>/revoke"
                        style="display:inline;"
                        data-confirm="<?= e(t('api_tokens.confirm_revoke')) ?>"
                        data-confirm-label="<?= e(t('api_tokens.revoke')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <button type="submit" class="btn-danger" data-action="revoke-token">
                      <i class="fa-solid fa-ban"></i> <?= e(t('api_tokens.revoke')) ?>
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
</section>

<script type="module" src="<?= e(asset_url('/assets/js/api-tokens.js')) ?>"></script>
