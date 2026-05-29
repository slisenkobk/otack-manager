<?php
// Flash messages render via <meta> tags + a global JS listener that pipes
// them through UI.toast — one consistent toast pattern across the app.
echo isset($_GET['saved']) ? flash_meta('Settings saved.', 'success') : '';
?>

<div style="display:flex;flex-direction:column;gap:24px;max-width:760px;margin:0 auto;">

  <form method="post" action="/admin/settings" class="brief" style="max-width:none;">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <h2 style="font-size:20px;font-weight:600;margin:0 0 6px;">Workspace</h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;">Brand name appears in the sidebar, page titles, public forms, and email subjects. Leave empty to fall back to "Otack Manager".</p>

    <div class="field-row" style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
      <div class="field">
        <label>App name</label>
        <input class="input" type="text" name="app_name" value="<?= e($values['app_name'] ?? '') ?>" placeholder="Otack Manager" maxlength="60">
      </div>
      <div class="field" style="min-width:0;">
        <label title="Drives logo, accents, links, focus ring, favicon.">Brand color</label>
        <?php $currentColor = $values['app_color'] ?? ''; ?>
        <div class="color-picker" data-color-picker-group>
          <input type="color"
                 value="<?= e($currentColor !== '' ? $currentColor : '#c2410c') ?>"
                 data-color-picker
                 aria-label="Brand color">
          <input type="hidden" name="app_color" data-color-value value="<?= e($currentColor) ?>">
          <button type="button" class="btn btn--ghost btn--sm" data-color-reset title="Reset to default">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset
          </button>
        </div>
      </div>
    </div>

    <div class="field" style="margin-top:14px;">
      <label>Timezone</label>
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
            <input type="text" placeholder="Search timezone…" data-custom-select-search-input>
          </div>
          <div class="custom-select__opts">
            <?php foreach ($timezones as $r): ?>
              <div class="custom-select__opt<?= $cur === $r['tz'] ? ' is-selected' : '' ?>" data-value="<?= e($r['tz']) ?>" data-search="<?= e(mb_strtolower($r['tz'] . ' ' . $r['offset'])) ?>">
                <span class="custom-select__opt-label"><?= e($r['tz']) ?></span>
                <span class="custom-select__opt-meta"><?= e($r['offset']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="custom-select__no-results" hidden>No matches.</div>
        </div>
        <input type="hidden" name="timezone" value="<?= e($cur) ?>">
      </div>
    </div>

    <h2 style="font-size:20px;font-weight:600;margin:28px 0 6px;">Contact info</h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;">Shown on the footer of every public Form (users can override per submission).</p>

    <div class="field">
      <label>Company name</label>
      <input class="input" type="text" name="contact_company_name" value="<?= e($values['contact_company_name'] ?? '') ?>">
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Contact email</label>
      <input class="input" type="email" name="contact_email" value="<?= e($values['contact_email'] ?? '') ?>">
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Contact phone</label>
      <input class="input" type="tel" name="contact_phone" value="<?= e($values['contact_phone'] ?? '') ?>">
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Contact address</label>
      <input class="input" type="text" name="contact_address" value="<?= e($values['contact_address'] ?? '') ?>">
    </div>
    <div class="field" style="margin-top:14px;">
      <label>Form footer text</label>
      <div class="wysiwyg-host">
        <div class="wysiwyg-editor"
             data-quill
             data-quill-target="#contact-default-text-hidden"
             data-placeholder="Standard text appended to every public form footer…"><?= $values['contact_default_text'] ?? '' ?></div>
      </div>
      <input type="hidden" id="contact-default-text-hidden" name="contact_default_text" value="<?= e($values['contact_default_text'] ?? '') ?>">
    </div>

    <div style="margin-top:22px;display:flex;gap:10px;">
      <button class="btn btn--primary submit" type="submit">Save settings</button>
    </div>
  </form>

</div>
<script type="module" src="/assets/js/settings.js"></script>
