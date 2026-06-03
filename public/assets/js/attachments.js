import { api, UI } from './ui.js';

document.querySelectorAll('.attachments-section').forEach(section => {
  const entityType = section.dataset.entityType;
  const entityId   = section.dataset.entityId;
  const grid       = section.querySelector('.attach-grid');
  const input      = section.querySelector('[data-attach-input]');

  const maxImage = +(document.querySelector('meta[name=upload-max-image]')?.content || 5242880);
  const maxFile  = +(document.querySelector('meta[name=upload-max-file]')?.content  || 52428800);

  // Wire existing server-rendered items
  grid.querySelectorAll('.attach-item').forEach(attachItem);

  function attachItem(item) {
    // Both the top "View" action button and the thumbnail <a> wrapper carry
    // data-action="lightbox" — wire each one or the obvious thumbnail click
    // becomes a dead href="#" navigating to the page top.
    item.querySelectorAll('[data-action=lightbox]').forEach(lb => {
      lb.addEventListener('click', e => { e.preventDefault(); openLightbox(item); });
    });

    const del = item.querySelector('[data-action=delete-attachment]');
    if (del) del.addEventListener('click', async e => {
      e.preventDefault();
      e.stopPropagation();
      const id = item.dataset.attachmentId;
      if (!await UI.confirm('Delete this attachment?', { danger: true, confirmLabel: 'Delete' })) return;
      try {
        await api('/api/attachments/' + id + '/delete', { method: 'POST' });
        item.remove();
        UI.toast('Attachment removed', 'success');
      } catch {}
    });

    // Click anywhere on the card triggers the primary action (lightbox / open / download),
    // unless the user clicked the delete button or an existing link inside the top action bar.
    item.style.cursor = 'pointer';
    item.addEventListener('click', e => {
      if (e.target.closest('[data-action=delete-attachment]')) return;
      if (e.target.closest('[data-action=lightbox]')) return; // its own handler fires
      if (e.target.closest('a[href]') && e.target.closest('.attach-item__top')) return;
      if (+item.dataset.isImage === 1) {
        openLightbox(item);
        return;
      }
      // Non-image: replicate the existing media link behaviour
      const media = item.querySelector('.attach-item__media');
      if (media && media.tagName === 'A') {
        media.click();
      } else {
        const url = item.dataset.url;
        if (!url) return;
        const a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.click();
      }
    });
  }

  function openLightbox(item) {
    const imageItems = [...grid.querySelectorAll('.attach-item[data-is-image="1"]')];
    const urls = imageItems.map(i => i.dataset.url);
    const idx  = imageItems.indexOf(item);
    UI.lightbox(urls, idx);
  }

  let activeUploads = 0;
  function uploadStarted() {
    activeUploads++;
    section.classList.add('is-uploading');
    if (!section.querySelector('.attach-loader')) {
      const ldr = document.createElement('div');
      ldr.className = 'attach-loader';
      const sp = document.createElement('span');
      sp.className = 'attach-loader__spinner';
      const tx = document.createElement('span');
      tx.textContent = 'Uploading…';
      ldr.appendChild(sp);
      ldr.appendChild(tx);
      section.appendChild(ldr);
    }
  }
  function uploadEnded() {
    activeUploads = Math.max(0, activeUploads - 1);
    if (activeUploads === 0) {
      section.classList.remove('is-uploading');
      section.querySelector('.attach-loader')?.remove();
    }
  }

  async function uploadFiles(files) {
    if (!files.length) return;
    uploadStarted();
    try {
      for (const file of files) {
        const isImage = file.type.startsWith('image/');
        const limit   = isImage ? maxImage : maxFile;
        if (file.size > limit) {
          UI.toast(`${file.name}: ${isImage ? 'Image' : 'File'} exceeds ${Math.round(limit / 1048576)} MB`, 'error');
          continue;
        }
        const fd = new FormData();
        fd.set('entity_type', entityType);
        fd.set('entity_id', entityId);
        // Always pass an explicit filename — some browsers send pasted files as "blob"
        // with no extension, which PHP can reject during MIME sniffing.
        fd.set('file', file, file.name || 'pasted-file');
        try {
          const res  = await api('/api/attachments', { method: 'POST', body: fd });
          const node = buildAttachmentNode(res.attachment);
          grid.appendChild(node);
          attachItem(node);
          UI.toast((file.name || 'file') + ' uploaded', 'success');
        } catch (err) {
          // api() already shows a generic toast — add context for common cases.
          const msg  = String(err?.message || '');
          const mb   = (file.size / 1048576).toFixed(1);
          // "No file" from server usually means PHP rejected the upload (post_max_size
          // exceeded) and dropped $_FILES entirely. Translate to something useful.
          if (/no file/i.test(msg)) {
            UI.toast(`${file.name || 'file'} (${mb} MB) — too big for the server. Ask admin to raise PHP upload limits.`, 'error');
          } else if (/exceeds|too large|413/i.test(msg)) {
            UI.toast(`${file.name || 'file'}: file is too large (${mb} MB)`, 'error');
          } else if (/not allowed|svg/i.test(msg)) {
            UI.toast(`${file.name || 'file'}: file type is not allowed`, 'error');
          }
        }
      }
    } finally {
      uploadEnded();
    }
  }

  input?.addEventListener('change', async (e) => {
    await uploadFiles([...e.target.files]);
    e.target.value = '';
  });

  // Paste-to-upload: capture clipboard files (Ctrl/Cmd+V) when the user is hovering
  // this section. Lets you screenshot → hover → paste, without focusing any field.
  // Each .attachments-section on the page registers its own listener; we mark the
  // paste event the first time it's handled so the second listener skips it
  // (otherwise the same File would be sent twice — second call gets empty clipboard
  // and PHP returns 422 "No file").
  let isHovering = false;
  section.addEventListener('mouseenter', () => { isHovering = true; });
  section.addEventListener('mouseleave', () => { isHovering = false; });
  document.addEventListener('paste', (e) => {
    if (!isHovering) return;
    if (e.__otackPasteHandled) return;
    e.__otackPasteHandled = true;
    handlePaste(e);
  });

  function handlePaste(e) {
    const items = e.clipboardData?.items || [];
    const files = [];
    for (const it of items) {
      if (it.kind !== 'file') continue;
      const f = it.getAsFile();
      if (!f || !f.size) continue;
      // Pasted screenshots usually arrive with no name (or "image.png") which trips up
      // PHP's MIME / extension handling. Always rebuild as a named File.
      const isGenericName = !f.name || f.name === 'image.png' || f.name === 'blob';
      if (isGenericName && f.type) {
        const ext = (f.type.split('/')[1] || 'bin').replace('jpeg', 'jpg');
        const ts  = new Date().toISOString().replace(/[:.]/g, '-');
        files.push(new File([f], `pasted-${ts}.${ext}`, { type: f.type }));
      } else {
        files.push(f);
      }
    }
    if (!files.length) return;
    e.preventDefault();
    UI.toast(files.length === 1 ? 'Uploading from clipboard…' : `Uploading ${files.length} files from clipboard…`);
    uploadFiles(files);
  }

  function kindOf(a) {
    if (a.is_image) return 'image';
    const mime = (a.mime || '').toLowerCase();
    const name = (a.original_name || '').toLowerCase();
    if (/(zip|rar|7z|tar|gz|bz2)$/.test(name)) return 'archive';
    if (['application/zip','application/x-7z-compressed','application/x-rar-compressed','application/x-tar','application/gzip'].includes(mime)) return 'archive';
    if (mime === 'application/pdf' || mime.startsWith('text/') || mime === 'application/json' || mime === 'application/xml') return 'viewable';
    return 'download';
  }

  function buildAttachmentNode(a) {
    const kind = kindOf(a);
    const art = document.createElement('article');
    art.className = 'attach-item attach-item--' + kind;
    art.dataset.attachmentId = a.id;
    art.dataset.isImage      = a.is_image ? '1' : '0';
    art.dataset.url          = a.url;
    art.dataset.name         = a.original_name;

    if (kind === 'image') {
      const link = document.createElement('a');
      link.href = '#';
      link.className = 'attach-item__media';
      link.dataset.action = 'lightbox';
      const img = document.createElement('img');
      img.src = a.url;
      img.alt = a.original_name;
      img.loading = 'lazy';
      link.appendChild(img);
      art.appendChild(link);
    } else {
      const link = document.createElement('a');
      link.className = 'attach-item__media attach-item__media--icon';
      link.href = a.url;
      if (kind === 'viewable') { link.target = '_blank'; link.rel = 'noopener'; }
      else link.download = '';
      const icon = document.createElement('i');
      icon.className = kind === 'archive' ? 'fa-solid fa-file-zipper'
                    : a.mime === 'application/pdf' ? 'fa-solid fa-file-pdf'
                    : (a.mime || '').startsWith('text/') ? 'fa-solid fa-file-lines'
                    : 'fa-solid fa-file';
      const nm = document.createElement('div');
      nm.className = 'attach-item__name';
      nm.textContent = a.original_name.length > 24 ? a.original_name.slice(0, 23) + '…' : a.original_name;
      const cta = document.createElement('span');
      cta.className = 'attach-item__cta';
      const ctaIcon = document.createElement('i');
      ctaIcon.className = kind === 'viewable' ? 'fa-solid fa-arrow-up-right-from-square' : 'fa-solid fa-download';
      cta.appendChild(ctaIcon);
      cta.appendChild(document.createTextNode(' ' + (kind === 'viewable' ? 'open' : 'download')));
      link.appendChild(icon);
      link.appendChild(nm);
      link.appendChild(cta);
      art.appendChild(link);
    }

    const meta = document.createElement('div');
    meta.className = 'attach-item__foot';
    const sz = document.createElement('span');
    sz.textContent = formatSize(a.size);
    meta.appendChild(sz);

    if (a.can_delete) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'attach-item__del';
      btn.dataset.action = 'delete-attachment';
      btn.setAttribute('aria-label', 'Delete');
      const i = document.createElement('i');
      i.className = 'fa-solid fa-xmark';
      btn.appendChild(i);
      meta.appendChild(btn);
    }

    art.appendChild(meta);
    return art;
  }

  function formatSize(b) {
    if (b < 1024)       return b + ' B';
    if (b < 1048576)    return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(1) + ' GB';
  }
});
