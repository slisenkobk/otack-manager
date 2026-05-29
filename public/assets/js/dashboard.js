import { api } from './ui.js';

const ICONS = {
  'comment.created':     'fa-regular fa-comment',
  'attachment.uploaded': 'fa-solid fa-paperclip',
  'task.status_changed': 'fa-solid fa-arrow-right-arrow-left',
  'task.created':        'fa-solid fa-plus',
  'form.submitted':      'fa-solid fa-fire',
};
const VERBS = {
  'comment.created':     'commented',
  'attachment.uploaded': 'attached file',
  'task.status_changed': 'changed status',
  'task.created':        'created task',
  'form.submitted':      'submitted form',
};

function tag(name, className, text) {
  const el = document.createElement(name);
  if (className) el.className = className;
  if (text != null) el.textContent = text;
  return el;
}

function link(href, text, className) {
  const a = tag('a', className, text);
  a.href = href;
  return a;
}

function renderActivityRow(a) {
  const isExternal = a.event === 'form.submitted';
  const row = tag('div', 'activity-row' + (isExternal ? ' activity-row--important' : ''));
  const iconClass = ICONS[a.event] || 'fa-regular fa-circle-dot';
  const verb = VERBS[a.event] || 'updated';
  const actor = isExternal ? 'Visitor' : a.actor_name;

  row.appendChild(tag('i', 'activity-row__icon ' + iconClass));
  row.appendChild(tag('span', 'activity-row__time mono', a.created_at));
  row.appendChild(tag('span', 'activity-row__actor', actor));
  row.appendChild(tag('span', 'activity-row__verb', verb));

  const target = tag('span', 'activity-row__target');
  if (a.task_id && a.task_url) {
    target.appendChild(link(a.task_url, a.task_title || ('Task #' + a.task_id), 'activity-row__link'));
    if (a.project_name && a.project_url) {
      target.appendChild(tag('span', 'activity-row__in', 'in'));
      target.appendChild(link(a.project_url, a.project_name, 'activity-row__link activity-row__link--muted'));
    }
  } else if (a.project_id && a.project_url) {
    target.appendChild(link(a.project_url, a.project_name || ('Project #' + a.project_id), 'activity-row__link'));
  }
  row.appendChild(target);

  if (a.summary) row.appendChild(tag('span', 'activity-row__summary', '— ' + a.summary));
  return row;
}

const btn = document.querySelector('.load-more-activity');
if (btn) {
  btn.addEventListener('click', async () => {
    const offset = parseInt(btn.dataset.offset, 10) || 10;
    btn.disabled = true;
    try {
      const res = await api('/api/activity?offset=' + offset);
      const list = document.getElementById('activity-list');
      if (list && res.items) {
        for (const item of res.items) list.appendChild(renderActivityRow(item));
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
