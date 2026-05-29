<?php
// Inputs: $values, $fields (SettingsController::KEYS), $csrfToken,
//         $timezones, $currentTab ∈ {workspace, contact}
echo isset($_GET['saved']) ? flash_meta(t('settings.saved'), 'success') : '';
$localeNames = locale_display_names();
?>

<div class="page-head">
  <div class="project-tabs">
    <a href="/admin/settings?tab=workspace"
       class="project-tab<?= $currentTab === 'workspace' ? ' project-tab--active' : '' ?>">
      <i class="fa-solid fa-briefcase" aria-hidden="true" style="margin-right:var(--space-2);"></i>
      <?= e(t('settings.tab.workspace')) ?>
    </a>
    <a href="/admin/settings?tab=contact"
       class="project-tab<?= $currentTab === 'contact' ? ' project-tab--active' : '' ?>">
      <i class="fa-solid fa-address-card" aria-hidden="true" style="margin-right:var(--space-2);"></i>
      <?= e(t('settings.tab.contact')) ?>
    </a>
  </div>
  <a href="/admin/compass" class="btn btn--secondary btn--sm page-head__btn"
     title="<?= e(t('settings.compass_hint')) ?>"
     style="text-decoration:none;display:inline-flex;align-items:center;gap:var(--space-2);">
    <i class="fa-solid fa-compass" aria-hidden="true"></i>
    <?= e(t('settings.compass_link')) ?>
  </a>
</div>

<form method="post" action="/admin/settings" class="brief brief--wide">
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
  <input type="hidden" name="_tab" value="<?= e($currentTab) ?>">

  <?php if ($currentTab === 'workspace'): ?>

    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 18px;"><?= e(t('settings.section.workspace_hint', ['fallback' => 'Otack Manager'])) ?></p>

    <div class="form-grid form-grid--2">
      <div class="field">
        <label><?= e(t('settings.field.app_name')) ?></label>
        <input class="input" type="text" name="app_name" value="<?= e($values['app_name'] ?? '') ?>" placeholder="Otack Manager" maxlength="60">
      </div>
      <div class="field">
        <label title="<?= e(t('settings.field.brand_color_title')) ?>"><?= e(t('settings.field.brand_color')) ?></label>
        <?php $currentColor = $values['app_color'] ?? ''; ?>
        <div class="color-picker" data-color-picker-group>
          <input type="color"
                 value="<?= e($currentColor !== '' ? $currentColor : '#c2410c') ?>"
                 data-color-picker
                 aria-label="<?= e(t('settings.field.brand_color_aria')) ?>">
          <input type="hidden" name="app_color" data-color-value value="<?= e($currentColor) ?>">
          <button type="button" class="btn btn--ghost btn--sm" data-color-reset title="<?= e(t('settings.field.brand_reset')) ?>">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> <?= e(t('common.reset')) ?>
          </button>
        </div>
      </div>

      <div class="field">
        <label><?= e(t('settings.field.timezone')) ?></label>
        <?php
          $cur = $values['timezone'] ?? 'Europe/Kyiv';
          $curOffset = '';
          foreach ($timezones as $r) { if ($r['tz'] === $cur) { $curOffset = $r['offset']; break; } }
        ?>
        <div class="custom-select" data-custom-select data-custom-select-search>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label"><?= e($cur) ?></span>
            <span class="custom-select__meta"><?= e($curOffset) ?></span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__search">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="<?= e(t('settings.field.timezone_search')) ?>" data-custom-select-search-input>
            </div>
            <div class="custom-select__opts">
              <?php foreach ($timezones as $r): ?>
                <div class="custom-select__opt<?= $cur === $r['tz'] ? ' is-selected' : '' ?>" data-value="<?= e($r['tz']) ?>" data-search="<?= e(mb_strtolower($r['tz'] . ' ' . $r['offset'])) ?>">
                  <span class="custom-select__opt-label"><?= e($r['tz']) ?></span>
                  <span class="custom-select__opt-meta"><?= e($r['offset']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="custom-select__no-results" hidden><?= e(t('common.no_results')) ?></div>
          </div>
          <input type="hidden" name="timezone" value="<?= e($cur) ?>">
        </div>
      </div>

      <div class="field">
        <label><?= e(t('settings.field.default_locale')) ?></label>
        <?php $curLocale = $values['default_locale'] ?? 'en'; ?>
        <div class="custom-select" data-custom-select>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label"><?= e($localeNames[$curLocale] ?? $curLocale) ?></span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <?php foreach (available_locales() as $code): ?>
                <div class="custom-select__opt<?= $code === $curLocale ? ' is-selected' : '' ?>" data-value="<?= e($code) ?>">
                  <span class="custom-select__opt-label"><?= e($localeNames[$code] ?? $code) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" name="default_locale" value="<?= e($curLocale) ?>">
        </div>
        <p class="muted" style="font-size:12px;color:var(--ink-3);margin:8px 0 0;"><?= e(t('settings.field.default_locale_hint')) ?></p>
      </div>
    </div>

  <?php else: /* contact tab */ ?>

    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 18px;"><?= e(t('settings.section.contact_hint')) ?></p>

    <div class="form-grid form-grid--2">
      <div class="field">
        <label><?= e(t('settings.field.company_name')) ?></label>
        <input class="input" type="text" name="contact_company_name" value="<?= e($values['contact_company_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= e(t('settings.field.contact_email')) ?></label>
        <input class="input" type="email" name="contact_email" value="<?= e($values['contact_email'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= e(t('settings.field.contact_phone')) ?></label>
        <input class="input" type="tel" name="contact_phone" value="<?= e($values['contact_phone'] ?? '') ?>">
      </div>
      <div class="field">
        <label><?= e(t('settings.field.contact_address')) ?></label>
        <input class="input" type="text" name="contact_address" value="<?= e($values['contact_address'] ?? '') ?>">
      </div>
    </div>
    <div class="field" style="margin-top:14px;">
      <label><?= e(t('settings.field.contact_footer')) ?></label>
      <div class="wysiwyg-host">
        <div class="wysiwyg-editor"
             data-quill
             data-quill-target="#contact-default-text-hidden"
             data-placeholder="<?= e(t('settings.field.contact_footer_placeholder')) ?>"><?= $values['contact_default_text'] ?? '' ?></div>
      </div>
      <input type="hidden" id="contact-default-text-hidden" name="contact_default_text" value="<?= e($values['contact_default_text'] ?? '') ?>">
    </div>

  <?php endif; ?>

  <div style="margin-top:22px;display:flex;gap:10px;">
    <button class="btn btn--primary submit" type="submit"><?= e(t('settings.save_button')) ?></button>
  </div>
</form>
<script type="module" src="<?= e(asset_url('/assets/js/settings.js')) ?>"></script>
