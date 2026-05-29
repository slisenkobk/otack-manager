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
      <a href="/forms" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> All forms</a>
    </div>
    <div class="builder-toolbar__right">
      <button type="button" class="btn-secondary" data-action="add-field"><i class="fa-solid fa-plus"></i> Add field</button>
      <?php if ($form): ?>
        <a class="btn-secondary" href="<?= e(\abs_url('/f/' . $form['hash'])) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Open public</a>
      <?php endif; ?>
      <button type="button" class="btn btn--primary submit" data-action="save-form"><i class="fa-solid fa-check"></i> Save form</button>
    </div>
  </div>

  <?php if ($form): ?>
    <section class="builder-panel">
      <h2 class="builder-panel__title">Public URL</h2>
      <div class="public-url-row" data-public-url-row data-form-id="<?= (int)$form['id'] ?>">
        <a class="public-url-row__url" data-public-url href="<?= e(\abs_url('/f/' . $form['hash'])) ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-link"></i>
          <span data-public-url-text><?= e(\abs_url('/f/' . $form['hash'])) ?></span>
        </a>
        <button type="button" class="btn-secondary" data-action="copy-url"><i class="fa-regular fa-copy"></i> Copy</button>
        <button type="button" class="btn-ghost" data-action="rotate-url" title="Generate a new link (the current one will stop working)">
          <i class="fa-solid fa-arrows-rotate"></i> Rotate
        </button>
      </div>
    </section>
  <?php endif; ?>

  <section class="builder-panel">
    <h2 class="builder-panel__title">Form basics</h2>
    <div class="builder-grid">
      <div class="field">
        <label>Title (shown to users)</label>
        <input class="input" type="text" data-form-title value="<?= e($form['title'] ?? '') ?>" placeholder="Customer enquiry">
      </div>
      <div class="field builder-grid__span">
        <label>Description (optional, supports formatting)</label>
        <div class="wysiwyg-host">
          <div class="wysiwyg-editor"
               data-quill
               data-quill-target="#form-description-hidden"
               data-placeholder="Brief context for the visitor…"><?= $form['description'] ?? '' ?></div>
        </div>
        <input type="hidden" id="form-description-hidden" value="<?= e($form['description'] ?? '') ?>">
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title">Fields</h2>
    <div data-fields-list class="fields-list"></div>
    <p class="muted builder-hint" data-fields-hint hidden>No fields yet — hit <strong>+ Add field</strong> above to start.</p>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title">Contact info block (read-only to the visitor)</h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;">Pick which contact lines to show on the public form footer. Empty overrides fall back to the global Settings values.</p>
    <div class="contact-grid" data-footer>
      <?php foreach ([
        'company' => ['Company name', 'company_name', 'text'],
        'email'   => ['Email',        'email',        'email'],
        'phone'   => ['Phone',        'phone',        'tel'],
        'address' => ['Address',      'address',      'text'],
        'note'    => ['Additional note', 'note',      'textarea'],
      ] as $k => [$label, $valKey, $inputType]): ?>
        <div class="contact-row">
          <label class="contact-row__toggle">
            <input type="checkbox" data-footer-show="<?= e('show_' . $k) ?>"> <?= e($label) ?>
          </label>
          <?php if ($inputType === 'textarea'): ?>
            <textarea class="textarea" rows="2" data-footer-val="<?= e($valKey) ?>" placeholder="Override the default (leave empty to use Settings)…"></textarea>
          <?php else: ?>
            <input class="input" type="<?= e($inputType) ?>" data-footer-val="<?= e($valKey) ?>" placeholder="Override (leave empty to use Settings)">
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
<script type="module" src="/assets/js/wysiwyg.js"></script>
<script type="module" src="/assets/js/form-builder.js"></script>
