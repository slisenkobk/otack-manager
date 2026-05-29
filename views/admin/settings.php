<?php
$saved = isset($_GET['saved']);
?>
<?php if ($saved): ?>
  <div class="toast toast--success" style="position:static;margin-bottom:16px;">Settings saved.</div>
<?php endif; ?>

<div style="display:flex;flex-direction:column;gap:24px;max-width:760px;">

  <form method="post" action="/admin/settings" class="brief" style="max-width:none;">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">

    <h2 style="font-size:20px;font-weight:600;margin:0 0 6px;">Workspace</h2>
    <p class="muted" style="font-size:12px;color:var(--ink-3);margin:0 0 16px;">Used to format dates/times across the manager.</p>

    <div class="field">
      <label>Timezone</label>
      <select class="input" name="timezone">
        <?php $cur = $values['timezone'] ?? 'Europe/Kyiv'; ?>
        <?php foreach ($timezones as $tz): ?>
          <option value="<?= e($tz) ?>"<?= $cur === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
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
