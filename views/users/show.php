<?php
// Admin-only user-detail page.
//
// Currently surfaces the user's metadata + a read-only API tokens table so
// admins can audit and revoke an individual user's personal-access tokens.
// Plaintext token values are NEVER shown here (admins didn't mint them);
// they only see prefix + status + timestamps. The bulk "Revoke all" button
// is hidden when there are no active tokens left to revoke.

echo !empty($success) ? flash_meta($success, 'success') : '';
echo !empty($error)   ? flash_meta($error,   'error')   : '';

$roleLabels = ['admin' => t('role.admin'), 'manager' => t('role.manager'), 'employee' => t('role.employee')];
$localeNames = locale_display_names();

$now = time();
$tokenStatus = function (array $t, int $now): string {
    if (!empty($t['revoked_at'])) return 'revoked';
    if (!empty($t['expires_at']) && (int)$t['expires_at'] <= $now) return 'expired';
    return 'active';
};
$tokenEpoch = function (?int $ts): string {
    if (!$ts) return '';
    try {
        return (new \DateTimeImmutable('@' . $ts))
            ->setTimezone(app_timezone())
            ->format('d.m.Y H:i');
    } catch (\Throwable $e) { return ''; }
};

$activeCount = 0;
foreach ($apiTokens as $tok) {
    if ($tokenStatus($tok, $now) === 'active') $activeCount++;
}
?>

<div class="page-actions u-mb-space-8">
  <a class="btn btn--secondary" href="/users">
    <i class="fa-solid fa-arrow-left"></i> <?= e(t('nav.users')) ?>
  </a>
</div>

<section class="brief brief--wide profile-card">
  <h2 class="profile-card__title"><?= e($user['name']) ?></h2>
  <div class="profile-card__body profile-card__body--user-summary">
    <?= user_avatar_html((int)$user['id'], $user['name'], $user['avatar'] ?? null, 'lg') ?>
    <div class="profile-card__user-info">
      <div class="data-table__sub" data-user-email><?= e($user['email']) ?></div>
      <div class="muted fz-12">
        <?= e($roleLabels[$user['role']] ?? $user['role']) ?>
        · <?= e(t('status.user.' . $user['status'])) ?>
        · <?= e($localeNames[$user['locale'] ?? 'en'] ?? ($user['locale'] ?? 'en')) ?>
      </div>
    </div>
  </div>
</section>

<section class="brief brief--wide profile-card" data-admin-tokens>
  <h2 class="profile-card__title"><?= e(t('api_tokens.admin_section_title')) ?></h2>
  <p class="muted profile-card__hint"><?= e(t('api_tokens.admin_no_value_note')) ?></p>

  <?php if (!$apiTokens): ?>
    <div class="empty-state u-mt-space-6">
      <p class="empty-state__text"><?= e(t('api_tokens.admin_empty_state')) ?></p>
    </div>
  <?php else: ?>
    <?php if ($activeCount > 0): ?>
      <div class="profile-form__actions u-mt-space-6">
        <form method="post"
              action="/users/<?= (int)$user['id'] ?>/tokens/revoke-all"
              data-confirm="<?= e(t('api_tokens.confirm_revoke_all')) ?>"
              data-confirm-label="<?= e(t('api_tokens.revoke_all')) ?>"
              class="m-0">
          <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
          <button type="submit" class="btn btn--danger" data-action="revoke-all-tokens">
            <i class="fa-solid fa-ban"></i> <?= e(t('api_tokens.revoke_all')) ?>
          </button>
        </form>
      </div>
    <?php endif; ?>

    <div class="data-table-wrap u-mt-space-6">
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
          <?php foreach ($apiTokens as $tok): ?>
            <?php
              $status = $tokenStatus($tok, $now);
              $statusKey = 'api_tokens.status_' . $status;
              $statusCls = 'status status--' . $status . ($status === 'active' ? ' is-ready' : '');
            ?>
            <tr data-token-row data-token-id="<?= (int)$tok['id'] ?>">
              <td data-token-name><?= e($tok['name']) ?></td>
              <td>
                <span class="mono fz-11 text-ink-3"><?= e($tok['prefix']) ?>…</span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e($tokenEpoch((int)$tok['created_at'])) ?></span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e($tokenEpoch(isset($tok['last_used_at']) ? (int)$tok['last_used_at'] : null)) ?></span>
              </td>
              <td>
                <span class="data-table__meta-time"><?= e($tokenEpoch(isset($tok['expires_at']) ? (int)$tok['expires_at'] : null)) ?></span>
              </td>
              <td>
                <span class="<?= e($statusCls) ?>" data-token-status><?= e(t($statusKey)) ?></span>
              </td>
              <td class="data-table__cell--actions">
                <?php if ($status === 'active'): ?>
                  <form method="post"
                        action="/users/<?= (int)$user['id'] ?>/tokens/<?= (int)$tok['id'] ?>/revoke"
                        class="d-inline"
                        data-confirm="<?= e(t('api_tokens.confirm_revoke')) ?>"
                        data-confirm-label="<?= e(t('api_tokens.revoke')) ?>">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                    <button type="submit" class="btn btn--danger" data-action="revoke-token">
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
