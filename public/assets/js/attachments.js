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
