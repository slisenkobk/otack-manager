import { api } from './ui.js';

const btn = document.querySelector('.load-more-activity');
if (btn) {
  btn.addEventListener('click', async () => {
    const offset = parseInt(btn.dataset.offset, 10) || 10;
    btn.disabled = true;
    try {
      const res = await api('/api/activity?offset=' + offset);
      const list = document.getElementById('activity-list');
      if (list && res.items) {
        for (const item of res.items) {
          const a = document.createElement('a');
          a.href = item.entity_url;
          a.style.cssText = 'display:flex;gap:16px;align-items:baseline;padding:10px 14px;border:1px solid var(--rule);text-decoration:none;color:var(--ink);background:var(--paper);';

          const ts = document.createElement('span');
          ts.className = 'mono';
          ts.style.cssText = 'font-size:10px;color:var(--ink-3);min-width:110px;letter-spacing:.08em;';
          ts.textContent = item.created_at;
          a.appendChild(ts);

          const author = document.createElement('span');
          author.style.cssText = 'font-weight:600;color:var(--ink-2);font-size:13px;min-width:120px;';
          author.textContent = item.author_name;
          a.appendChild(author);

          const body = document.createElement('span');
          body.style.cssText = 'font-size:13px;flex:1;color:var(--ink-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';

          const onText = document.createTextNode('on ');
          body.appendChild(onText);

          const em = document.createElement('em');
          em.style.cssText = 'font-style:normal;color:var(--brand);font-weight:600;';
          em.textContent = item.entity_type;
          body.appendChild(em);

          body.appendChild(document.createTextNode(': ' + item.body_snippet));
          a.appendChild(body);

          list.appendChild(a);
        }
      }
      btn.dataset.offset = String(offset + 10);
      if (!res.has_more) {
        btn.parentElement?.remove();
      } else {
        btn.disabled = false;
      }
    } catch {
      btn.disabled = false;
    }
  });
}
