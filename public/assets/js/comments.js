import { api, UI } from './ui.js';
import { logSilent } from './utils.js';

function avatarBg(userId) {
  // Mirrors PHP helper user_color(): hsl((id*47) % 360, 55%, 48%)
  const hue = ((+userId || 0) * 47) % 360;
  return `hsl(${hue}, 55%, 48%)`;
}

function makeIcon(faClasses) {
  const i = document.createElement('i');
  i.className = faClasses;
  return i;
}

function buildComment(c, isReply) {
  const article = document.createElement('article');
  article.className = 'comment' + (isReply ? ' comment--reply' : '');
  article.dataset.commentId = c.id;

  const av = document.createElement('span');
  av.className = 'user-avatar user-avatar--sm comment__avatar';
  if (c.author_avatar) {
    av.classList.add('user-avatar--img');
    const img = document.createElement('img');
    img.src = '/' + String(c.author_avatar).replace(/^\/+/, '');
    img.alt = c.author_name || '';
    img.loading = 'lazy';
    av.appendChild(img);
  } else {
    av.style.background = avatarBg(c.user_id);
    av.textContent = c.author_name ? c.author_name.substring(0, 1) : '?';
  }
  article.appendChild(av);

  const main = document.createElement('div');
  main.className = 'comment__main';

  const meta = document.createElement('div');
  meta.className = 'comment-meta';
  const name = document.createElement('span');
  name.className = 'comment-meta__name';
  name.textContent = c.author_name || '';
  meta.appendChild(name);
  {
    const reply = document.createElement('button');
    reply.type = 'button';
    reply.className = 'comment-reply-btn';
    reply.dataset.action = 'reply';
    reply.appendChild(makeIcon('fa-solid fa-reply'));
    reply.appendChild(document.createTextNode(' Reply'));
    meta.appendChild(reply);
  }
  const ts = document.createElement('span');
  ts.className = 'comment-meta__time';
  ts.textContent = 'just now';
  meta.appendChild(ts);
  main.appendChild(meta);

  const bodyEl = document.createElement('div');
  bodyEl.className = 'comment-body';
  // safe: Markdown::render() pre-escapes all user text; only library markup remains
  bodyEl.innerHTML = c.body_html; // nosec: server-sanitized HTML from Markdown service
  main.appendChild(bodyEl);

  if (c.attachments && c.attachments.length) {
    const row = document.createElement('div');
    row.className = 'comment-attachments';
    for (const att of c.attachments) row.appendChild(buildAttachmentChip(att));
    main.appendChild(row);
  }

  article.appendChild(main);
  return article;
}

function buildAttachmentChip(att) {
  const link = document.createElement('a');
  link.href = att.url || ('/' + att.filename);
  link.target = '_blank';
  link.className = 'comment-attachment';
  link.appendChild(makeIcon('fa-solid fa-' + (att.is_image ? 'image' : 'paperclip')));
  const fname = att.original_name || att.filename || '';
  const span = document.createElement('span');
  span.textContent = fname.length > 30 ? fname.substring(0, 30) + '…' : fname;
  link.appendChild(span);
  return link;
}

function buildPendingChip(file, index, onRemove) {
  const chip = document.createElement('span');
  chip.style.cssText = 'display:inline-flex;align-items:center;gap:5px;padding:3px 8px;background:var(--paper-2);border:1px solid var(--rule);border-radius:3px;font-size:11px;color:var(--ink-2);font-family:var(--font-mono);';
  chip.dataset.pendingIndex = String(index);
  chip.appendChild(makeIcon('fa-solid fa-paperclip'));
  chip.appendChild(document.createTextNode(' ' + file.name.substring(0, 25)));
  const rm = document.createElement('button');
  rm.type = 'button';
  rm.style.cssText = 'background:none;border:none;cursor:pointer;color:var(--ink-3);padding:0;margin-left:4px;font-size:11px;';
  rm.appendChild(makeIcon('fa-solid fa-xmark'));
  rm.addEventListener('click', () => onRemove(parseInt(chip.dataset.pendingIndex, 10)));
  chip.appendChild(rm);
  return chip;
}

function buildReplyForm() {
  const form = document.createElement('form');
  form.className = 'comment-composer comment-composer--reply';
  form.dataset.composer = 'reply';

  const ta = document.createElement('textarea');
  ta.className = 'textarea';
  ta.name = 'body';
  ta.placeholder = 'Write a reply…';
  ta.rows = 2;
  form.appendChild(ta);

  const row = document.createElement('div');
  row.className = 'comment-composer__row';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn-ghost';
  cancel.dataset.action = 'cancel-reply';
  cancel.style.fontSize = '12px';
  cancel.textContent = 'Cancel';
  row.appendChild(cancel);
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'submit';
  submit.textContent = 'Reply →';
  row.appendChild(submit);
  form.appendChild(row);

  return form;
}

function updateTreeLine(group) {
  if (!group) return;
  const replies = group.querySelector('.comment-replies');
  // Look for the last actual reply comment, ignoring any open composer at the bottom.
  const articles = replies ? replies.querySelectorAll(':scope > .comment--reply') : [];
  const last = articles[articles.length - 1];
  if (!last || (replies && replies.hidden)) {
    group.style.removeProperty('--tree-end');
    return;
  }
  // .comment-replies is not positioned, so last.offsetTop is relative to .comment-group already.
  // Trunk starts at group's top:33px (just below parent avatar). Stop at last reply's avatar centre (~+17px).
  const end = last.offsetTop + 17 - 33;
  group.style.setProperty('--tree-end', Math.max(0, end) + 'px');
}

document.querySelectorAll('.comment-group').forEach(updateTreeLine);

document.querySelectorAll('.comment-thread').forEach(thread => {
  const entityType = thread.dataset.entityType;
  const entityId   = thread.dataset.entityId;
  const list       = thread.querySelector('.comment-list');
  const rootForm   = thread.querySelector('.comment-composer[data-composer="root"]');

  if (rootForm) wireComposer(rootForm, null);
  list.querySelectorAll('.comment').forEach(c => wireCommentActions(c));

  async function postComment(body, parentId) {
    return api('/api/comments', {
      method: 'POST',
      body: JSON.stringify({
        entity_type: entityType,
        entity_id: +entityId,
        body,
        ...(parentId ? { parent_id: parentId } : {}),
      }),
    });
  }

  async function uploadAttachments(commentId, files) {
    const uploaded = [];
    for (const file of files) {
      try {
        const fd = new FormData();
        fd.append('entity_type', 'comment');
        fd.append('entity_id', String(commentId));
        fd.append('file', file);
        const res = await api('/api/attachments', { method: 'POST', body: fd });
        if (res.attachment) uploaded.push(res.attachment);
      } catch { /* non-fatal */ }
    }
    return uploaded;
  }

  function insertNewComment(commentNode, parentId, parentArticle) {
    if (!parentId) {
      const group = document.createElement('div');
      group.className = 'comment-group';
      group.dataset.rootId = commentNode.dataset.commentId;
      group.appendChild(commentNode);
      const replies = document.createElement('div');
      replies.className = 'comment-replies';
      replies.hidden = true;
      group.appendChild(replies);
      list.appendChild(group);
    } else {
      const group = parentArticle.closest('.comment-group');
      let replies = group.querySelector('.comment-replies');
      if (!replies) {
        replies = document.createElement('div');
        replies.className = 'comment-replies';
        group.appendChild(replies);
      }
      replies.hidden = false;
      replies.appendChild(commentNode);
      requestAnimationFrame(() => updateTreeLine(group));
    }
    wireCommentActions(commentNode);
  }

  function wireCommentActions(article) {
    const del = article.querySelector('[data-action="delete-comment"]');
    if (del) {
      del.addEventListener('click', async () => {
        if (!await UI.confirm('Delete this comment?', { danger: true, confirmLabel: 'Delete' })) return;
        try {
          await api('/api/comments/' + article.dataset.commentId + '/delete', { method: 'POST' });
          const group = article.closest('.comment-group');
          if (group && group.querySelector('.comment') === article) {
            group.remove();
          } else {
            article.remove();
          }
          UI.toast('Comment deleted', 'success');
        } catch (e) { logSilent(e, 'comments.delete'); }
      });
    }
    const reply = article.querySelector('[data-action="reply"]');
    if (reply) reply.addEventListener('click', () => toggleReplyForm(article));
  }

  // Open the reply composer at the bottom of the thread (after the last child),
  // regardless of which comment the Reply button was clicked on. Threads are
  // flat (single level), so parent_id always resolves to the root anyway.
  function toggleReplyForm(originArticle) {
    const group = originArticle.closest('.comment-group');
    const rootArticle = group ? group.querySelector('.comment:not(.comment--reply)') : originArticle;
    let replies = group ? group.querySelector('.comment-replies') : null;
    if (!replies) {
      replies = document.createElement('div');
      replies.className = 'comment-replies';
      group.appendChild(replies);
    }
    const existing = replies.querySelector(':scope > .comment-composer--reply');
    if (existing) { existing.remove(); if (!replies.querySelector(':scope > .comment--reply')) replies.hidden = true; return; }
    replies.hidden = false;
    const form = buildReplyForm();
    replies.appendChild(form);
    form.querySelector('[data-action=cancel-reply]').addEventListener('click', () => {
      form.remove();
      if (!replies.querySelector(':scope > .comment--reply')) replies.hidden = true;
    });
    const ta = form.querySelector('textarea');
    setTimeout(() => ta.focus(), 0);
    ta.addEventListener('keydown', e => { if (e.key === 'Escape') form.remove(); });
    wireComposer(form, parseInt(rootArticle.dataset.commentId, 10), rootArticle);
  }

  function wireComposer(form, parentId, parentArticle) {
    const pendingFiles = [];
    const fileInput = form.querySelector('[data-comment-attach]');
    const pendingContainer = form.querySelector('.comment-pending-attachments');

    const renderChips = () => {
      if (!pendingContainer) return;
      pendingContainer.replaceChildren();
      pendingFiles.forEach((f, i) => pendingContainer.appendChild(
        buildPendingChip(f, i, idx => { pendingFiles.splice(idx, 1); renderChips(); })
      ));
    };

    if (fileInput) {
      fileInput.addEventListener('change', () => {
        for (const file of fileInput.files) pendingFiles.push(file);
        fileInput.value = '';
        renderChips();
      });
    }

    const textarea = form.querySelector('textarea[name=body]');
    if (textarea) {
      // Enter posts; Shift+Enter inserts newline.
      textarea.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
      });
      textarea.addEventListener('paste', (e) => {
        const items = e.clipboardData?.items || [];
        let added = 0;
        for (const it of items) {
          if (it.kind !== 'file') continue;
          const file = it.getAsFile();
          if (!file || !file.type.startsWith('image/')) continue;
          const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
          const ts = new Date().toISOString().replace(/[:.]/g, '-');
          pendingFiles.push(new File([file], `pasted-${ts}.${ext}`, { type: file.type }));
          added++;
        }
        if (added > 0) {
          e.preventDefault();
          renderChips();
          UI.toast(added === 1 ? 'Image attached from clipboard' : added + ' images attached');
        }
      });
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const body = textarea.value.trim();
      if (!body) return;

      let res;
      try {
        res = await postComment(body, parentId);
      } catch { return; }

      const files = pendingFiles.splice(0, pendingFiles.length);
      renderChips();
      const uploaded = files.length ? await uploadAttachments(res.comment.id, files) : [];
      res.comment.attachments = uploaded;

      const node = buildComment(res.comment, !!parentId);
      insertNewComment(node, parentId, parentArticle);

      textarea.value = '';
      UI.toast(parentId ? 'Reply posted' : 'Comment posted', 'success');
      if (parentId) form.remove();
    });
  }
});
