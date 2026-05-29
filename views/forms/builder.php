<?php
$fieldsJson = $form ? (string)$form['fields_json'] : '[]';
$footerJson = $form ? (string)$form['footer_json']
                    : json_encode([
                        'show_company'=>true, 'show_email'=>true, 'show_phone'=>true,
                        'show_address'=>false, 'show_note'=>true,
                        'company_name'=>'', 'email'=>'', 'phone'=>'', 'address'=>'', 'note'=>'',
                      ]);
?>

<link rel="stylesheet" href="/assets/vendor/quill/quill.snow.css">

<div class="form-builder" data-form-builder data-form-id="<?= $form ? (int)$form['id'] : '' ?>">

  <div class="builder-toolbar" data-builder-toolbar>
    <div class="builder-toolbar__left">
      <a href="/forms" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('forms.all_forms_link')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <button type="button" class="btn-secondary" data-action="add-field"><i class="fa-solid fa-plus"></i> <?= e(t('forms.add_field')) ?></button>
      <?php if ($form): ?>
        <a class="btn-secondary" href="<?= e(\abs_url('/f/' . $form['hash'])) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('forms.open_public')) ?></a>
      <?php endif; ?>
      <button type="button" class="btn btn--primary submit" data-action="save-form"><i class="fa-solid fa-check"></i> <?= e(t('forms.save_form')) ?></button>
    </div>
  </div>

  <?php if ($form): ?>
    <section class="builder-panel">
      <h2 class="builder-panel__title"><?= e(t('forms.public_url')) ?></h2>
      <div class="public-url-row" data-public-url-row data-form-id="<?= (int)$form['id'] ?>">
        <a class="public-url-row__url" data-public-url href="<?= e(\abs_url('/f/' . $form['hash'])) ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-link"></i>
          <span data-public-url-text><?= e(\abs_url('/f/' . $form['hash'])) ?></span>
        </a>
        <button type="button" class="btn-secondary" data-action="copy-url"><i class="fa-regular fa-copy"></i> <?= e(t('forms.copy')) ?></button>
        <button type="button" class="btn-ghost" data-action="rotate-url" title="<?= e(t('forms.rotate_title')) ?>">
          <i class="fa-solid fa-arrows-rotate"></i> <?= e(t('forms.rotate')) ?>
        </button>
      </div>
    </section>
  <?php endif; ?>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('forms.basics')) ?></h2>
    <div class="builder-grid">
      <div class="field">
        <label><?= e(t('forms.title_label')) ?></label>
        <input class="input" type="text" data-form-title value="<?= e($form['title'] ?? '') ?>" placeholder="<?= e(t('forms.title_placeholder')) ?>">
      </div>
      <div class="field builder-grid__span">
        <label><?= e(t('forms.description_label')) ?></label>
        <div class="wysiwyg-host">
          <div class="wysiwyg-editor"
               data-quill
               data-quill-target="#form-description-hidden"
               data-placeholder="<?= e(t('forms.description_placeholder')) ?>"><?= $form['description'] ?? '' ?></div>
        </div>
        <input type="hidden" id="form-description-hidden" value="<?= e($form['description'] ?? '') ?>">
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('forms.fields')) ?></h2>
    <div data-fields-list class="fields-list"></div>
    <p class="muted builder-hint" data-fields-hint hidden><?= t('forms.no_fields', ['button' => '<strong>' . e(t('forms.add_field_inline')) . '</strong>']) ?></p>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('forms.contact_block')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('forms.contact_hint')) ?></p>
    <div class="contact-grid" data-footer>
      <?php foreach ([
        'company' => [t('forms.contact.company'),   'company_name', 'text'],
        'email'   => [t('forms.contact.email_lbl'), 'email',        'email'],
        'phone'   => [t('forms.contact.phone'),     'phone',        'tel'],
        'address' => [t('forms.contact.address'),   'address',      'text'],
        'note'    => [t('forms.contact.note'),      'note',         'textarea'],
      ] as $k => [$label, $valKey, $inputType]): ?>
        <div class="contact-row">
          <label class="contact-row__toggle">
            <input type="checkbox" data-footer-show="<?= e('show_' . $k) ?>"> <?= e($label) ?>
          </label>
          <?php if ($inputType === 'textarea'): ?>
            <textarea class="textarea" rows="2" data-footer-val="<?= e($valKey) ?>" placeholder="<?= e(t('forms.contact.note_placeholder')) ?>"></textarea>
          <?php else: ?>
            <input class="input" type="<?= e($inputType) ?>" data-footer-val="<?= e($valKey) ?>" placeholder="<?= e(t('forms.contact.placeholder')) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

</div>

<script type="application/json" id="builder-state-fields"><?= $fieldsJson ?></script>
<script type="application/json" id="builder-state-footer"><?= $footerJson ?></script>
<script src="/assets/vendor/quill/quill.min.js"></script>
<script src="/assets/vendor/sortable.min.js"></script>
<script type="module" src="<?= e(asset_url('/assets/js/wysiwyg.js')) ?>"></script>
<script type="module" src="<?= e(asset_url('/assets/js/form-builder.js')) ?>"></script>
