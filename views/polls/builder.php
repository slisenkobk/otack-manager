<?php
$fields = $poll
    ? (json_decode((string)$poll['fields_json'], true) ?: $defaultFields)
    : $defaultFields;
// Hydrate the locked contact label + the choice options for the static template.
$contactLabel = 'Email';
$choiceLabel  = 'Choose one';
$choiceOpts   = [];
foreach ($fields as $f) {
    if (($f['key'] ?? '') === 'contact') $contactLabel = (string)($f['label'] ?? $contactLabel);
    if (($f['key'] ?? '') === 'choice') {
        $choiceLabel = (string)($f['label'] ?? $choiceLabel);
        foreach ((array)($f['options'] ?? []) as $opt) {
            if (is_array($opt)) {
                $choiceOpts[] = ['key' => (string)($opt['key'] ?? ''), 'label' => (string)($opt['label'] ?? '')];
            } else {
                $choiceOpts[] = ['key' => '', 'label' => (string)$opt];
            }
        }
    }
}
if (!$choiceOpts) {
    $choiceOpts = [['key' => 'opt_1', 'label' => 'Option 1'], ['key' => 'opt_2', 'label' => 'Option 2']];
}

$contactField = (string)($poll['contact_field'] ?? 'email');
$pollLocale   = (string)($poll['locale'] ?? user_locale());
$localeNames  = locale_display_names();

$attachedProjectId = $poll && !empty($poll['project_id']) ? (int)$poll['project_id'] : 0;
$successMessage    = $poll ? (string)($poll['success_message'] ?? '') : '';
$attachedProjectName = '';
foreach (($projects ?? []) as $p) {
    if ((int)$p['id'] === $attachedProjectId) { $attachedProjectName = (string)$p['name']; break; }
}
?>

<link rel="stylesheet" href="/assets/vendor/quill/quill.snow.css">

<div class="poll-builder" data-poll-builder data-poll-id="<?= $poll ? (int)$poll['id'] : '' ?>">

  <div class="builder-toolbar" data-builder-toolbar>
    <div class="builder-toolbar__left">
      <a href="/polls" class="builder-toolbar__back"><i class="fa-solid fa-arrow-left"></i> <?= e(t('polls.all_polls_link')) ?></a>
    </div>
    <div class="builder-toolbar__right">
      <?php if ($poll): ?>
        <button type="button" class="btn--secondary" data-action="activate-poll"
                data-confirm="<?= e(t('polls.activate_confirm')) ?>"><i class="fa-solid fa-play"></i> <?= e(t('polls.activate')) ?></button>
      <?php endif; ?>
      <button type="button" class="btn btn--primary submit" data-action="save-poll"><i class="fa-solid fa-check"></i> <?= e(t('polls.save_poll')) ?></button>
    </div>
  </div>

  <?php if ($poll): ?>
    <section class="builder-panel">
      <h2 class="builder-panel__title"><?= e(t('polls.public_url')) ?></h2>
      <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 12px;"><?= e(t('polls.public_url_hint_draft')) ?></p>
      <div class="public-url-row" data-public-url-row data-poll-id="<?= (int)$poll['id'] ?>">
        <a class="public-url-row__url" data-public-url href="<?= e(\abs_url('/p/' . $poll['hash'])) ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-link"></i>
          <span data-public-url-text><?= e(\abs_url('/p/' . $poll['hash'])) ?></span>
        </a>
        <button type="button" class="btn--secondary" data-action="copy-url"><i class="fa-regular fa-copy"></i> <?= e(t('polls.copy')) ?></button>
        <button type="button" class="btn--ghost" data-action="rotate-url" title="<?= e(t('polls.rotate_title')) ?>">
          <i class="fa-solid fa-arrows-rotate"></i> <?= e(t('polls.rotate')) ?>
        </button>
      </div>
    </section>
  <?php endif; ?>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.basics')) ?></h2>
    <div class="builder-grid">
      <div class="field">
        <label for="f-poll-title"><?= e(t('polls.title_label')) ?></label>
        <input id="f-poll-title" class="input" type="text" data-poll-title value="<?= e($poll['title'] ?? '') ?>" placeholder="<?= e(t('polls.title_placeholder')) ?>">
      </div>
      <div class="field">
        <label><?= e(t('polls.locale_label')) ?></label>
        <div class="custom-select" data-custom-select data-poll-locale-wrap>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label"><?= e($localeNames[$pollLocale] ?? $pollLocale) ?></span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <?php foreach (available_locales() as $code): ?>
                <div class="custom-select__opt<?= $code === $pollLocale ? ' is-selected' : '' ?>" data-value="<?= e($code) ?>">
                  <span class="custom-select__opt-label"><?= e($localeNames[$code] ?? $code) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" data-poll-locale value="<?= e($pollLocale) ?>">
        </div>
      </div>
      <div class="field builder-grid__span">
        <label><?= e(t('polls.description_label')) ?></label>
        <div class="wysiwyg-host">
          <div class="wysiwyg-editor"
               data-quill
               data-quill-target="#poll-description-hidden"
               data-placeholder="<?= e(t('polls.description_placeholder')) ?>"><?= $poll['description'] ?? '' ?></div>
        </div>
        <input type="hidden" id="poll-description-hidden" value="<?= e($poll['description'] ?? '') ?>">
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.contact_section')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('polls.contact_section_hint')) ?></p>
    <div class="builder-grid">
      <div class="field">
        <label><?= e(t('polls.contact_kind_label')) ?></label>
        <div class="radio-row" data-contact-field-row>
          <label class="radio-pill">
            <input type="radio" name="poll_contact_field" value="email"<?= $contactField === 'email' ? ' checked' : '' ?>>
            <?= e(t('polls.contact_kind.email')) ?>
          </label>
          <label class="radio-pill">
            <input type="radio" name="poll_contact_field" value="phone"<?= $contactField === 'phone' ? ' checked' : '' ?>>
            <?= e(t('polls.contact_kind.phone')) ?>
          </label>
        </div>
      </div>
      <div class="field">
        <label for="f-poll-contact-label"><?= e(t('polls.contact_label_label')) ?></label>
        <input id="f-poll-contact-label" class="input" type="text" data-contact-label value="<?= e($contactLabel) ?>" placeholder="<?= e(t('polls.contact_label_placeholder')) ?>">
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.choice_section')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('polls.choice_section_hint')) ?></p>
    <div class="field">
      <label for="f-poll-choice-label"><?= e(t('polls.choice_label_label')) ?></label>
      <input id="f-poll-choice-label" class="input" type="text" data-choice-label value="<?= e($choiceLabel) ?>" placeholder="<?= e(t('polls.choice_label_placeholder')) ?>">
    </div>
    <div class="field">
      <label><?= e(t('polls.choice_options_label')) ?></label>
      <div data-options-list class="options-list">
        <?php foreach ($choiceOpts as $i => $opt): ?>
          <div class="options-list__row" data-option-row>
            <input class="input options-list__input"
                   type="text"
                   data-option-input
                   data-option-key="<?= e($opt['key']) ?>"
                   value="<?= e($opt['label']) ?>"
                   placeholder="<?= e(t('polls.option_placeholder', ['n' => $i + 1])) ?>">
            <button type="button" class="btn--ghost" data-action="remove-option" title="<?= e(t('common.remove')) ?>"><i class="fa-solid fa-xmark"></i></button>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn--secondary" data-action="add-option" style="margin-top:8px;">
        <i class="fa-solid fa-plus"></i> <?= e(t('polls.add_option')) ?>
      </button>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.integration.title')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('polls.integration.hint')) ?></p>
    <div class="builder-grid">
      <div class="field">
        <label><?= e(t('polls.integration.project_label')) ?></label>
        <div class="custom-select" data-custom-select data-poll-project-wrap>
          <button type="button" class="custom-select__btn">
            <span class="custom-select__label">
              <?= $attachedProjectId ? e($attachedProjectName) : e(t('polls.integration.no_project')) ?>
            </span>
            <i class="fa-solid fa-chevron-down custom-select__chevron"></i>
          </button>
          <div class="custom-select__pop" hidden>
            <div class="custom-select__opts">
              <div class="custom-select__opt<?= $attachedProjectId === 0 ? ' is-selected' : '' ?>" data-value="">
                <span class="custom-select__opt-label"><?= e(t('polls.integration.no_project')) ?></span>
              </div>
              <?php foreach (($projects ?? []) as $p): ?>
                <div class="custom-select__opt<?= (int)$p['id'] === $attachedProjectId ? ' is-selected' : '' ?>" data-value="<?= (int)$p['id'] ?>">
                  <span class="custom-select__opt-label"><?= e($p['name']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <input type="hidden" data-poll-project value="<?= $attachedProjectId ?: '' ?>">
        </div>
      </div>
    </div>
  </section>

  <section class="builder-panel">
    <h2 class="builder-panel__title"><?= e(t('polls.success.section_title')) ?></h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;"><?= e(t('polls.success.hint')) ?></p>
    <div class="field">
      <label for="f-poll-success-message"><?= e(t('polls.success.label')) ?></label>
      <textarea id="f-poll-success-message" class="textarea" rows="3"
                data-success-message
                maxlength="2000"
                placeholder="<?= e(t('polls.thanks_body_default')) ?>"><?= e($successMessage) ?></textarea>
    </div>
  </section>


</div>

<script type="application/json" id="poll-builder-state-fields"><?= htmlspecialchars(json_encode($fields, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></script>
<script type="module" src="<?= e(asset_url('/assets/js/poll-builder.js')) ?>"></script>
