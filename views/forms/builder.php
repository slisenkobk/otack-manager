<?php
$fieldsJson = $form ? (string)$form['fields_json'] : '[]';
$footerJson = $form ? (string)$form['footer_json']
                    : json_encode([
                        'show_company'=>true, 'show_email'=>true, 'show_phone'=>true,
                        'show_address'=>false, 'show_note'=>true,
                        'company_name'=>'', 'email'=>'', 'phone'=>'', 'address'=>'', 'note'=>'',
                      ]);
$formLocale  = $form['locale'] ?? user_locale();
$localeNames = locale_display_names();

$attachedProjectId = $form && !empty($form['project_id']) ? (int)$form['project_id'] : 0;
$autoCreateTask    = $form && !empty($form['auto_create_task']);
$taskTitleTemplate = $form ? (string)($form['task_title_template'] ?? '') : '';
$successMessage    = $form ? (string)($form['success_message'] ?? '') : '';
$attachedProjectName = '';
foreach (($projects ?? []) as $p) {
    if ((int)$p['id'] === $attachedProjectId) { $attachedProjectName = (string)$p['name']; break; }
}
?>

<link rel="stylesheet" href="/assets/vendor/quill/quill.snow.css">

<div class="form-builder" data-form-builder data-form-id="<?= $form ? (int)$form['id'] : '' ?>">

  <div class="builder-toolbar" data-builder-toolbar>
    <div class="builder-toolbar__left">
      <a href="/forms" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('forms.all_forms_link')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <button type="button" class="btn--secondary" data-action="add-field"><i class="fa-solid fa-plus"></i> <?= e(t('forms.add_field')) ?></button>
      <?php if ($form): ?>
        <a class="btn--secondary" href="<?= e(\abs_url('/f/' . $form['hash'])) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> <?= e(t('forms.open_public')) ?></a>
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
        <button type="button" class="btn--secondary" data-action="copy-url"><i class="fa-regular fa-copy"></i> <?= e(t('forms.copy')) ?></button>
        <button type="button" class="btn--ghost" data-action="rotate-url" title="<?= e(t('forms.rotate_title')) ?>">
          <i class="fa-solid fa-arrows-rotate"></i> <?= e(t('forms.rotate')) ?>
        </button>
      </div>
    </section>
  <?php endif; ?>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('forms.basics')) ?></h2>
    <div class="builder-grid">
      <div class="field">
        <label for="f-form-title"><?= e(t('forms.title_label')) ?></label>
        <input id="f-form-title" class="input" type="text" data-form-title value="<?= e($form['title'] ?? '') ?>" placeholder="<?= e(t('forms.title_placeholder')) ?>">
      </div>
      <div class="field">
        <label><?= e(t('forms.locale_label')) ?></label>
        <div class="custom-select" data-custom-select data-form-locale-wrap>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label"><?= e($localeNames[$formLocale] ?? $formLocale) ?></span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <?php foreach (available_locales() as $code): ?>
                <div class="custom-select__opt<?= $code === $formLocale ? ' is-selected' : '' ?>" data-value="<?= e($code) ?>">
                  <span class="custom-select__opt-label"><?= e($localeNames[$code] ?? $code) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" data-form-locale value="<?= e($formLocale) ?>">
        </div>
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

  <section class="builder-panel" data-integration>
    <h2 class="builder-panel__title"><?= e(t('forms.integration.title')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('forms.integration.hint')) ?></p>

    <div class="builder-grid">
      <div class="field">
        <label><?= e(t('forms.integration.project_label')) ?></label>
        <div class="custom-select" data-custom-select data-form-project-wrap>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label">
              <?= $attachedProjectId ? e($attachedProjectName) : e(t('forms.integration.no_project')) ?>
            </span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <div class="custom-select__opt<?= $attachedProjectId === 0 ? ' is-selected' : '' ?>" data-value="">
                <span class="custom-select__opt-label"><?= e(t('forms.integration.no_project')) ?></span>
              </div>
              <?php foreach (($projects ?? []) as $p): ?>
                <div class="custom-select__opt<?= (int)$p['id'] === $attachedProjectId ? ' is-selected' : '' ?>" data-value="<?= (int)$p['id'] ?>">
                  <span class="custom-select__opt-label"><?= e($p['name']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" data-form-project value="<?= $attachedProjectId ?: '' ?>">
        </div>
      </div>

      <div class="field builder-grid__span" data-auto-task-row<?= $attachedProjectId ? '' : ' hidden' ?>>
        <label class="contact-row__toggle">
          <input type="checkbox" data-auto-create-task<?= $autoCreateTask ? ' checked' : '' ?>>
          <?= e(t('forms.integration.auto_create_task')) ?>
        </label>
      </div>

      <div class="field builder-grid__span" data-task-template-row<?= ($attachedProjectId && $autoCreateTask) ? '' : ' hidden' ?>>
        <label for="f-task-title-template"><?= e(t('forms.integration.task_title_label')) ?></label>
        <input id="f-task-title-template" class="input" type="text" data-task-title-template
               value="<?= e($taskTitleTemplate) ?>"
               placeholder="<?= e(t('forms.integration.task_title_placeholder')) ?>">
        <p class="muted" style="font-size:11px;color:var(--ink-3);margin:6px 0 0;">
          <?= t('forms.integration.task_title_hint', [
              'formName'    => '<code>{formName}</code>',
              'submission'  => '<code>{submission.id}</code>',
              'fieldKey'    => '<code>{field_key}</code>',
          ]) ?>
        </p>
      </div>
    </div>
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

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('forms.success.section_title')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('forms.success.hint')) ?></p>
    <div class="field">
      <label for="f-success-message"><?= e(t('forms.success.label')) ?></label>
      <textarea id="f-success-message" class="textarea" rows="3"
                data-success-message
                maxlength="2000"
                placeholder="<?= e(t('public_form.thanks_body')) ?>"><?= e($successMessage) ?></textarea>
    </div>
  </section>

</div>

<script type="application/json" id="builder-state-fields"><?= $fieldsJson ?></script>
<script type="application/json" id="builder-state-footer"><?= $footerJson ?></script>
<script src="/assets/vendor/sortable.min.js"></script>
<script type="module" src="<?= e(asset_url('/assets/js/form-builder.js')) ?>"></script>
