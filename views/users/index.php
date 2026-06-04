<div class="page-actions head-with-actions">
  <form method="get" action="/users" class="search-field search-field--with-submit m-0">
    <i class="fa-solid fa-magnifying-glass muted fz-12"></i>
    <input class="input input--inline" name="q" placeholder="<?= e(t('users.search_placeholder')) ?>" value="<?= e($query ?? '') ?>" data-user-search>
    <button type="submit" class="search-field__submit" aria-label="<?= e(t('common.search')) ?>"><i class="fa-solid fa-arrow-right"></i></button>
  </form>
  <button type="button" class="btn btn--secondary" data-action="new-user">
    <i class="fa-solid fa-user-plus"></i> <?= e(t('users.new_user')) ?>
  </button>
</div>

<?php if (!$users): ?>
  <div class="empty-state">
    <span class="empty-state__tag"><?= e(app_name()) ?></span>
    <p class="empty-state__text"><?= !empty($query) ? e(t('users.empty.match', ['q' => $query])) : e(t('users.empty.none')) ?></p>
  </div>
<?php else: ?>
<?php
$roleLabels = ['admin' => t('role.admin'), 'manager' => t('role.manager'), 'employee' => t('role.employee')];
$localeNames = locale_display_names();
?>
<div class="data-table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th><?= e(t('users.table.user')) ?></th>
        <th><?= e(t('users.table.role')) ?></th>
        <th><?= e(t('users.table.status')) ?></th>
        <th><?= e(t('users.table.language')) ?></th>
        <th><?= e(t('users.table.joined')) ?></th>
        <th class="data-table__cell--actions"><?= e(t('common.actions')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr data-user-id="<?= (int)$u['id'] ?>" data-user-locale="<?= e($u['locale'] ?? 'en') ?>">
          <td>
            <div class="data-table__cell--user">
              <?= user_avatar_html((int)$u['id'], $u['name'], $u['avatar'] ?? null, 'md') ?>
              <div class="min-w-0">
                <div class="data-table__name" data-user-name><?= e($u['name']) ?></div>
                <div class="data-table__sub" data-user-email><?= e($u['email']) ?></div>
              </div>
            </div>
          </td>
          <td>
            <?php if ((int)$u['id'] !== (int)$currentUserId): ?>
              <div class="custom-select custom-select--compact w-140" data-custom-select>
                <button type="button" class="custom-select__btn">
                  <span class="custom-select__label"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
                  <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
                </button>
                <div class="custom-select__pop" hidden>
                  <div class="custom-select__opts">
                    <?php foreach ($roleLabels as $rVal => $rLabel): ?>
                      <div class="custom-select__opt<?= $u['role'] === $rVal ? ' is-selected' : '' ?>" data-value="<?= e($rVal) ?>">
                        <span class="custom-select__opt-label"><?= e($rLabel) ?></span>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <input type="hidden" data-role-select value="<?= e($u['role']) ?>">
              </div>
            <?php else: ?>
              <span class="mono fz-11 text-ink-3"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status<?= $u['status'] === 'approved' ? ' is-ready' : '' ?>"><?= e(t('status.user.' . $u['status'])) ?></span>
          </td>
          <td>
            <span class="mono fz-11 text-ink-3"><?= e($localeNames[$u['locale'] ?? 'en'] ?? ($u['locale'] ?? 'en')) ?></span>
          </td>
          <td>
            <span class="data-table__meta-time"><?= fmt_date($u['created_at']) ?></span>
          </td>
          <td class="data-table__cell--actions">
            <div class="user-actions" data-actions>
              <?php if ($u['status'] === 'pending'): ?>
                <button class="btn--secondary" data-action="approve" type="button" title="<?= e(t('users.action.approve')) ?>">
                  <i class="fa-solid fa-check"></i> <?= e(t('users.action.approve')) ?>
                </button>
              <?php endif; ?>
              <a class="btn--secondary" href="/users/<?= (int)$u['id'] ?>" title="<?= e(t('users.action.view')) ?>">
                <i class="fa-solid fa-eye"></i>
              </a>
              <button class="btn--secondary" data-action="edit-user" type="button" title="<?= e(t('users.action.edit')) ?>">
                <i class="fa-solid fa-pen"></i>
              </button>
              <?php if ($u['status'] !== 'blocked' && (int)$u['id'] !== (int)$currentUserId): ?>
                <button class="btn--secondary" data-action="block" type="button" title="<?= e(t('users.action.block')) ?>">
                  <i class="fa-solid fa-ban"></i>
                </button>
              <?php endif; ?>
              <?php if ((int)$u['id'] !== (int)$currentUserId): ?>
                <button class="btn--danger" data-action="delete" type="button" title="<?= e(t('users.action.delete')) ?>">
                  <i class="fa-solid fa-trash"></i>
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
  $hrefBuilder = function (int $p) use ($query) {
      return '/users?page=' . $p . (!empty($query) ? '&q=' . urlencode($query) : '');
  };
  require APP_ROOT . '/views/partials/pagination.php';
?>
<?php endif; ?>

<script type="module" src="/assets/js/users.js"></script>
