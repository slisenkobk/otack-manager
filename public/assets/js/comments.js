import { api, UI } from './ui.js';

document.querySelectorAll('.comment-thread').forEach(thread => {
  const entityType = thread.dataset.entityType;
  const entityId   = thread.dataset.entityId;
  const list       = thread.querySelector('.comment-list');
  const form       = thread.querySelector('.comment-composer');

  // Pending files state per-form
  let pendingFiles = [];

  // File attachment input handler
  const fileInput = form?.querySelector('[data-comment-attach]');
  const pendingContainer = form?.querySelector('.comment-pending-attachments');

  if (fileInput) {
    fileInput.addEventListener('change', () => {
      for (const file of fileInput.files) {
        pendingFiles.push(file);
        if (pendingContainer) {
          const chip = buildPendingChip(file, pendingFiles.length - 1);
          pendingContainer.appendChild(chip);
        }
      }
      fileInput.value = '';
    });
  }

  // Paste-to-attach: capture clipboard images (Ctrl/Cmd+V) in the textarea.
  const textareaForPaste = form?.querySelector('textarea[name=body]');
  if (textareaForPaste) {
    textareaForPaste.addEventListener('paste', (e) => {
      const items = e.clipboardData?.items || [];
      let added = 0;
      for (const it of items) {
        if (it.kind !== 'file') continue;
        const file = it.getAsFile();
        if (!file || !file.type.startsWith('image/')) continue;
        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        const ts = new Date().toISOString().replace(/[:.]/g, '-');
        const named = new File([file], `pasted-${ts}.${ext}`, { type: file.type });
        pendingFiles.push(named);
        if (pendingContainer) {
          pendingContainer.appendChild(buildPendingChip(named, pendingFiles.length - 1));
        }
        added++;
      }
      if (added > 0) {
        e.preventDefault(); // we handled it
        UI.toast(added === 1 ? 'Image attached from clipboard' : added + ' images attached');
      }
    });
  }

  function buildPendingChip(file, index) {
    const chip = document.createElement('span');
    chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:var(--paper-2);border:1px solid var(--rule);border-radius:3px;font-size:11px;color:var(--ink-2);font-family:var(--font-mono);';
    chip.dataset.pendingIndex = String(index);

    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-paperclip';
    chip.appendChild(icon);

    chip.appendChild(document.createTextNode(' ' + file.name.substring(0, 25)));

    const rm = document.createElement('button');
    rm.type = 'button';
    rm.style.cssText = 'background:none;border:none;cursor:pointer;color:var(--ink-3);padding:0;margin-left:4px;font-size:11px;';
    rm.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    rm.addEventListener('click', () => {
      const idx = parseInt(chip.dataset.pendingIndex, 10);
      pendingFiles.splice(idx, 1);
      // Rebuild chips
      renderPendingChips();
    });
    chip.appendChild(rm);
    return chip;
  }

  function renderPendingChips() {
    if (!pendingContainer) return;
    pendingContainer.innerHTML = '';
    pendingFiles.forEach((file, i) => {
      const chip = buildPendingChip(file, i);
      pendingContainer.appendChild(chip);
    });
  }

  // Submit new comment
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const textarea = form.querySelector('textarea[name=body]');
    const body = textarea.value.trim();
    if (!body) return;

    let commentRes;
    try {
      commentRes = await api('/api/comments', {
        method: 'POST',
        body: JSON.stringify({ entity_type: entityType, entity_id: +entityId, body }),
      });
    } catch {
      // error already shown by api() via UI.toast
      return;
    }

    // Upload any pending files
    const uploadedAttachments = [];
    const filesToUpload = pendingFiles.slice();
    pendingFiles = [];
    if (pendingContainer) pendingContainer.innerHTML = '';

    for (const file of filesToUpload) {
      try {
        const fd = new FormData();
        fd.append('entity_type', 'comment');
        fd.append('entity_id', String(commentRes.comment.id));
        fd.append('file', file);
        const attRes = await api('/api/attachments', { method: 'POST', body: fd });
        if (attRes.attachment) uploadedAttachments.push(attRes.attachment);
      } catch {
        // non-fatal — comment already posted
      }
    }

    commentRes.comment.attachments = uploadedAttachments;
    list.appendChild(buildComment(commentRes.comment));
    textarea.value = '';
  });

  // Attach delete to existing comments on page load
  list.querySelectorAll('[data-action="delete-comment"]').forEach(btn => attachDelete(btn));

  function attachDelete(btn) {
    btn.addEventListener('click', async () => {
      const article = btn.closest('.comment');
      const id = article.dataset.commentId;
      if (!await UI.confirm('Delete this comment?', { danger: true, confirmLabel: 'Delete' })) return;
      try {
        await api('/api/comments/' + id + '/delete', { method: 'POST' });
        article.remove();
      } catch {
        // error already shown by api() via UI.toast
      }
    });
  }

  function buildComment(c) {
    const a = document.createElement('article');
    a.className = 'comment';
    a.dataset.commentId = c.id;

    // Avatar initial — fills the 32px first grid column
    const ini = document.createElement('div');
    ini.className = 'ini';
    ini.style.cssText = 'width:32px;height:32px;font-size:11px;background:var(--ink);flex-shrink:0;margin-top:2px;';
    ini.textContent = c.author_name ? c.author_name.substring(0, 2) : '??';
    a.appendChild(ini);

    // Content wrapper — second grid column
    const content = document.createElement('div');
    content.style.cssText = 'min-width:0;';

    // Meta row
    const meta = document.createElement('div');
    meta.className = 'comment-meta';
    meta.style.cssText = 'display:flex;align-items:center;gap:10px;font-size:12px;color:var(--ink-3);margin-bottom:6px;';

    const name = document.createElement('span');
    name.style.cssText = 'font-weight:600;color:var(--ink-2);';
    name.textContent = c.author_name;
    meta.appendChild(name);

    const ts = document.createElement('span');
    ts.textContent = 'just now';
    meta.appendChild(ts);

    if (c.can_delete) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.dataset.action = 'delete-comment';
      btn.style.cssText = 'margin-left:auto;background:none;border:none;cursor:pointer;color:var(--ink-3);font-size:12px;';
      const icon = document.createElement('i');
      icon.className = 'fa-solid fa-xmark';
      btn.appendChild(icon);
      attachDelete(btn);
      meta.appendChild(btn);
    }

    content.appendChild(meta);

    // Body: body_html is produced by App\Service\Markdown which
    // escapes all input via htmlspecialchars before generating any HTML tags,
    // so the only HTML present is our own safe markup (strong, code, a, ul, etc.).
    // No raw user content ever reaches the DOM as HTML.
    const bodyEl = document.createElement('div');
    bodyEl.className = 'comment-body';
    bodyEl.style.cssText = 'font-size:14px;line-height:1.55;';
    // nosec: safe — server-rendered markdown output, all user text is htmlspecialchars-escaped
    bodyEl.innerHTML = c.body_html; // safe: Markdown::render() pre-escapes all input
    content.appendChild(bodyEl);

    // Attachment chips for newly uploaded files
    if (c.attachments && c.attachments.length > 0) {
      const attRow = document.createElement('div');
      attRow.className = 'comment-attachments';
      attRow.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;';
      for (const att of c.attachments) {
        const chip = buildAttachmentChip(att);
        attRow.appendChild(chip);
      }
      content.appendChild(attRow);
    }

    a.appendChild(content);

    return a;
  }

  function buildAttachmentChip(att) {
    const link = document.createElement('a');
    link.href = att.url || ('/' + att.filename);
    link.target = '_blank';
    link.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:3px 8px;background:var(--paper-2);border:1px solid var(--rule);border-radius:3px;font-size:11px;color:var(--ink-2);text-decoration:none;font-family:var(--font-mono);';

    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-' + (att.is_image ? 'image' : 'paperclip');
    link.appendChild(icon);

    const fname = att.original_name || att.filename || '';
    link.appendChild(document.createTextNode(' ' + (fname.length > 30 ? fname.substring(0, 30) + '…' : fname)));

    return link;
  }
});
