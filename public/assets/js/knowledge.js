// Knowledge base — page module.
//
// Two surfaces:
//   - editor (views/knowledge/edit.php): Edit/Preview tab toggle. Preview
//     pings POST /knowledge/preview which returns server-rendered HTML
//     through the same Markdown::renderRich pipeline used on /show.
//   - show page (views/knowledge/show.php): comment form + delete button.
//     Inline AJAX so the page doesn't reload on each post.
//
// Imported once via <script type="module"> on both views; harmless when
// the matching DOM nodes aren't present.
//
// XSS posture: every user-supplied string goes through `textContent` /
// element attribute setters — never HTML interpolation. The two specific
// places that DO inject HTML (Markdown preview and comment body) take
// already-sanitized output from the server (HtmlSanitizer::cleanRich for
// the preview, Markdown::render which pre-escapes for comments). Those
// two sites are isolated in `injectSanitizedHtml()` and `setProse()` so
// the trust boundary is auditable.

import { UI } from './ui.js';
import { api } from './ui-fetch.js';
import { t } from './utils.js';

/**
 * Single audited XSS sink. Caller MUST guarantee `html` was produced by
 * a server-side sanitiser (HtmlSanitizer::clean / cleanRich). The two
 * call-sites in this file are the Markdown preview and a comment body
 * — both hit those sanitisers before reaching us.
 */
function injectSanitizedHtml(el, html) {
  el.innerHTML = html;
}

function setMutedText(el, text) {
  el.textContent = '';
  const p = document.createElement('p');
  p.className = 'muted';
  p.textContent = text;
  el.appendChild(p);
}

// ─── Editor: Edit/Preview tabs ───────────────────────────────────────
function initEditor() {
  const root = document.querySelector('[data-knowledge-editor]');
  if (!root) return;
  const tabs    = root.querySelectorAll('[data-editor-tab]');
  const source  = root.querySelector('[data-editor-source]');
  const preview = root.querySelector('[data-editor-preview]');
  if (!tabs.length || !source || !preview) return;

  async function switchTo(mode) {
    tabs.forEach(b => b.classList.toggle('is-active', b.dataset.editorTab === mode));
    if (mode === 'preview') {
      source.hidden  = true;
      preview.hidden = false;
      setMutedText(preview, t('js.knowledge.preview_loading'));
      try {
        const data = await api('/knowledge/preview', {
          method: 'POST',
          body: JSON.stringify({ body_md: source.value || '' }),
        });
        const html = (data && data.body_html) || '';
        if (html.trim() === '') {
          setMutedText(preview, t('js.knowledge.preview_empty'));
        } else {
          // Safe: html came from POST /knowledge/preview which renders
          // via Markdown::renderRich → HtmlSanitizer::cleanRich.
          injectSanitizedHtml(preview, html);
        }
      } catch (e) {
        setMutedText(preview, t('js.knowledge.preview_failed'));
      }
    } else {
      preview.hidden = true;
      source.hidden  = false;
    }
  }

  tabs.forEach(b => b.addEventListener('click', () => switchTo(b.dataset.editorTab)));

  // ─── Markdown toolbar ─────────────────────────────────────────────
  // Translates clicks on the toolbar buttons into a markdown-syntax edit
  // of the textarea. Inline marks (bold, italic, code, strike) wrap the
  // current selection; block marks (headings, lists, quote, code-block,
  // table, hr) prepend/insert lines without clobbering existing content.
  const toolbar = root.querySelector('[data-editor-toolbar]');
  if (toolbar && source) {
    toolbar.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-md]');
      if (!btn) return;
      e.preventDefault();
      applyMd(source, btn.dataset.md);
    });
  }
}

// All mutations go through replaceSelection() which uses
// document.execCommand('insertText'). That's the one path that keeps
// the textarea's native undo stack intact, so Cmd/Ctrl+Z and the
// toolbar's Undo button both work. execCommand is deprecated on paper
// but every shipping browser still implements it for textareas and
// no spec'd replacement preserves undo. If/when that changes, swap
// to InputEvent + ElementInternals once browsers ship it.
function replaceSelection(textarea, replacement, cursorOffsetFromEnd = 0, selectionLength = 0) {
  textarea.focus();
  const ok = document.execCommand && document.execCommand('insertText', false, replacement);
  if (!ok) {
    // Fallback for browsers where insertText returns false (rare):
    // dispatch an InputEvent so frameworks observing the textarea pick
    // it up, then rewrite value. Undo will not be restorable in this path.
    const start = textarea.selectionStart;
    const end   = textarea.selectionEnd;
    textarea.value = textarea.value.slice(0, start) + replacement + textarea.value.slice(end);
    const cursor = start + replacement.length - cursorOffsetFromEnd;
    textarea.setSelectionRange(cursor - selectionLength, cursor);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    return;
  }
  if (cursorOffsetFromEnd > 0 || selectionLength > 0) {
    const cursor = textarea.selectionStart - cursorOffsetFromEnd;
    textarea.setSelectionRange(cursor - selectionLength, cursor);
  }
}

function applyMd(textarea, kind) {
  if (kind === 'undo') { textarea.focus(); document.execCommand('undo'); return; }
  if (kind === 'redo') { textarea.focus(); document.execCommand('redo'); return; }

  const start = textarea.selectionStart;
  const end   = textarea.selectionEnd;
  const value = textarea.value;
  const sel   = value.slice(start, end);

  const INLINE_WRAP = {
    bold:   { l: '**', r: '**', placeholder: 'bold text' },
    italic: { l: '*',  r: '*',  placeholder: 'italic text' },
    strike: { l: '~~', r: '~~', placeholder: 'strikethrough' },
    code:   { l: '`',  r: '`',  placeholder: 'code' },
  };
  const LINE_PREFIX = {
    h1: '# ', h2: '## ', h3: '### ',
    ul: '- ', ol: '1. ', quote: '> ',
  };
  const INSERT_BLOCK = {
    link:      () => { const u = prompt(t('js.knowledge.link_url') || 'URL', 'https://'); return u ? `[${sel || t('js.knowledge.link_text') || 'link text'}](${u})` : null; },
    codeblock: () => "```\n" + (sel || 'code block') + "\n```",
    hr:        () => "\n---\n",
    table:     () => "\n| Column 1 | Column 2 |\n|----------|----------|\n| cell     | cell     |\n",
  };

  if (INLINE_WRAP[kind]) {
    const m = INLINE_WRAP[kind];
    const inner = sel || m.placeholder;
    const replacement = m.l + inner + m.r;
    // Position cursor inside the wrap, selecting placeholder text.
    replaceSelection(textarea, replacement, m.r.length, inner.length);
    return;
  }

  if (LINE_PREFIX[kind]) {
    const prefix = LINE_PREFIX[kind];
    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    // Select from lineStart to end so the prefix is applied to whole lines.
    textarea.setSelectionRange(lineStart, end);
    if (sel.length > 0 && sel.includes('\n')) {
      const lines = value.slice(lineStart, end).split('\n');
      replaceSelection(textarea, lines.map(l => prefix + l).join('\n'));
    } else {
      replaceSelection(textarea, prefix + value.slice(lineStart, end));
    }
    return;
  }

  if (INSERT_BLOCK[kind]) {
    const piece = INSERT_BLOCK[kind]();
    if (piece === null) return;
    replaceSelection(textarea, piece);
    return;
  }
}

// ─── Show page: comments ────────────────────────────────────────────
function initComments() {
  const root = document.querySelector('[data-knowledge-comments]');
  if (!root) return;
  const slug = root.dataset.pageSlug;
  if (!slug) return;
  const form  = root.querySelector('[data-knowledge-comment-form]');
  const list  = root.querySelector('[data-comment-list]');
  if (!form || !list) return;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = form.querySelector('[data-comment-input]');
    const body  = (input?.value || '').trim();
    if (body === '') return;
    try {
      const data = await api(`/knowledge/${encodeURIComponent(slug)}/comments`, {
        method: 'POST',
        body: JSON.stringify({ body }),
      });
      if (data && data.comment) {
        list.appendChild(buildCommentNode(data.comment));
        if (input) input.value = '';
        UI.toast(t('js.knowledge.comment_added'), 'success');
      }
    } catch (e) { /* api() already toasted */ }
  });

  // Convention matches tasks/projects/polls: Enter submits, Shift+Enter
  // inserts a newline. Cmd/Ctrl+Enter also submits for muscle memory.
  const taInput = form.querySelector('[data-comment-input]');
  if (taInput) {
    taInput.addEventListener('keydown', (e) => {
      if (e.key !== 'Enter') return;
      if (e.shiftKey) return;
      e.preventDefault();
      form.requestSubmit();
    });
  }

  list.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-action="delete-comment"]');
    if (!btn) return;
    const row = btn.closest('[data-comment-id]');
    const id  = row?.dataset.commentId;
    if (!id) return;
    const ok = await UI.confirm(btn.dataset.confirm || t('js.knowledge.delete_confirm'), {
      danger: true,
      confirmLabel: btn.dataset.confirmLabel || t('js.confirm.delete'),
    });
    if (!ok) return;
    try {
      await api(`/knowledge/${encodeURIComponent(slug)}/comments/${id}/delete`, { method: 'POST' });
      row.remove();
    } catch (e) { /* api() already toasted */ }
  });
}

/**
 * Build a comment row out of DOM nodes — every user-supplied string
 * goes through `textContent` / attribute setters. Only the comment body
 * is injected as HTML, and it comes from server-side
 * Markdown::render() (which pre-escapes input before applying its tiny
 * grammar) — see `injectSanitizedHtml()`.
 */
function buildCommentNode(c) {
  const row = document.createElement('div');
  row.className = 'knowledge-comment';
  row.dataset.commentId = String(c.id);

  const head = document.createElement('div');
  head.className = 'knowledge-comment__head';

  const avatar = document.createElement('span');
  avatar.className = 'avatar avatar--sm ini';
  const author = String(c.author_name || '');
  avatar.textContent = author.slice(0, 2).toUpperCase();
  head.appendChild(avatar);

  const name = document.createElement('strong');
  name.textContent = author;
  head.appendChild(name);

  const stamp = document.createElement('span');
  stamp.className = 'muted fz-12';
  const when = c.created_at ? new Date(c.created_at) : null;
  stamp.textContent = when && !isNaN(when) ? when.toLocaleString() : '';
  head.appendChild(stamp);

  if (c.can_delete) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn--ghost btn--sm ml-auto';
    btn.dataset.action = 'delete-comment';
    btn.dataset.confirm = t('js.knowledge.delete_confirm');
    btn.dataset.confirmLabel = t('js.confirm.delete');
    const icon = document.createElement('i');
    icon.className = 'fa-solid fa-trash';
    btn.appendChild(icon);
    head.appendChild(btn);
  }

  row.appendChild(head);

  const bodyEl = document.createElement('div');
  bodyEl.className = 'knowledge-comment__body';
  // Safe: server-rendered through Markdown::render which pre-escapes the
  // input before applying its tiny markdown grammar.
  injectSanitizedHtml(bodyEl, c.body_html || '');
  row.appendChild(bodyEl);

  return row;
}

// Destructive forms (delete page, delete category). Convention from
// api-tokens.js — codebase forbids native confirm(), uses UI.confirm().
function initConfirmForms() {
  document.querySelectorAll('form[data-confirm]').forEach((form) => {
    if (form.dataset.confirmWired === '1') return;
    form.dataset.confirmWired = '1';
    form.addEventListener('submit', async (e) => {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      const ok = await UI.confirm(form.dataset.confirm || 'Are you sure?', {
        danger: true,
        confirmLabel: form.dataset.confirmLabel || t('js.confirm.delete'),
      });
      if (!ok) return;
      form.dataset.confirmed = '1';
      form.submit();
    });
  });
}

initEditor();
initComments();
initConfirmForms();
