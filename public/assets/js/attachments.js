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
    const lb = item.querySelector('[data-action=lightbox]');
    if (lb) lb.addEventListener('click', e => { e.preventDefault(); openLightbox(item); });

    const del = item.querySelector('[data-action=delete-attachment]');
    if (del) del.addEventListener('click', async e => {
      e.preventDefault();
      const id = item.dataset.attachmentId;
      if (!await UI.confirm('Delete this attachment?', { danger: true, confirmLabel: 'Delete' })) return;
      try {
        await api('/api/attachments/' + id + '/delete', { method: 'POST' });
        item.remove();
      } catch {}
    });
  }

  function openLightbox(item) {
    const imageItems = [...grid.querySelectorAll('.attach-item[data-is-image="1"]')];
    const urls = imageItems.map(i => i.dataset.url);
    const idx  = imageItems.indexOf(item);
    UI.lightbox(urls, idx);
  }

  input?.addEventListener('change', async (e) => {
    const files = [...e.target.files];
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
      fd.set('file', file);
      try {
        const res  = await api('/api/attachments', { method: 'POST', body: fd });
        const node = buildAttachmentNode(res.attachment);
        grid.appendChild(node);
        attachItem(node);
      } catch {}
    }
    e.target.value = '';
  });

  function buildAttachmentNode(a) {
    const art = document.createElement('article');
    art.className = 'attach-item';
    art.dataset.attachmentId = a.id;
    art.dataset.isImage      = a.is_image ? '1' : '0';
    art.dataset.url          = a.url;
    art.dataset.name         = a.original_name;
    art.style.cssText = 'position:relative;border:1px solid var(--rule);border-radius:4px;overflow:hidden;background:var(--paper);';

    if (a.is_image) {
      const link = document.createElement('a');
      link.href        = '#';
      link.className   = 'attach-img';
      link.dataset.action = 'lightbox';
      link.style.cssText  = 'display:block;height:120px;';
      const img = document.createElement('img');
      img.src       = a.url;
      img.alt       = a.original_name;
      img.loading   = 'lazy';
      img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
      link.appendChild(img);
      art.appendChild(link);
    } else {
      const link = document.createElement('a');
      link.href      = a.url;
      link.download  = '';
      link.style.cssText = 'display:flex;flex-direction:column;align-items:center;justify-content:center;height:120px;text-decoration:none;color:var(--ink-2);';
      const icon = document.createElement('i');
      icon.className    = 'fa-solid fa-file';
      icon.style.cssText = 'font-size:32px;color:var(--ink-3);';
      const nm = document.createElement('div');
      nm.style.cssText = 'font-size:11px;margin-top:8px;font-family:var(--font-mono);text-align:center;padding:0 6px;word-break:break-all;line-height:1.3;';
      nm.textContent   = (a.original_name.length > 24 ? a.original_name.slice(0, 23) + '…' : a.original_name);
      link.appendChild(icon);
      link.appendChild(nm);
      art.appendChild(link);
    }

    const meta = document.createElement('div');
    meta.style.cssText = 'position:absolute;bottom:0;left:0;right:0;background:rgba(26,22,18,0.85);color:var(--paper);padding:3px 6px;font-size:10px;font-family:var(--font-mono);display:flex;justify-content:space-between;align-items:center;';
    const sz = document.createElement('span');
    sz.textContent = formatSize(a.size);
    meta.appendChild(sz);

    if (a.can_delete) {
      const btn = document.createElement('button');
      btn.type              = 'button';
      btn.dataset.action    = 'delete-attachment';
      btn.style.cssText     = 'background:none;border:none;color:var(--paper);cursor:pointer;padding:0;';
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
