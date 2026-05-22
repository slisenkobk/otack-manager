import { api, UI } from './ui.js';

document.querySelectorAll('.comment-thread').forEach(thread => {
  const entityType = thread.dataset.entityType;
  const entityId   = thread.dataset.entityId;
  const list       = thread.querySelector('.comment-list');
  const form       = thread.querySelector('.comment-composer');

  // Submit new comment
  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const textarea = form.querySelector('textarea[name=body]');
    const body = textarea.value.trim();
    if (!body) return;
    try {
      const res = await api('/api/comments', {
        method: 'POST',
        body: JSON.stringify({ entity_type: entityType, entity_id: +entityId, body }),
      });
      list.appendChild(buildComment(res.comment));
      textarea.value = '';
    } catch {
      // error already shown by api() via UI.toast
    }
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
    a.style.cssText = 'background:var(--paper);border:1px solid var(--rule);padding:12px;border-radius:4px;';

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

    a.appendChild(meta);

    // Body: body_html is produced by App\Service\Markdown which
    // escapes all input via htmlspecialchars before generating any HTML tags,
    // so the only HTML present is our own safe markup (strong, code, a, ul, etc.).
    // No raw user content ever reaches the DOM as HTML.
    const bodyEl = document.createElement('div');
    bodyEl.className = 'comment-body';
    bodyEl.style.cssText = 'font-size:14px;line-height:1.55;';
    // nosec: safe — server-rendered markdown output, all user text is htmlspecialchars-escaped
    bodyEl.innerHTML = c.body_html; // safe: Markdown::render() pre-escapes all input
    a.appendChild(bodyEl);

    return a;
  }
});
