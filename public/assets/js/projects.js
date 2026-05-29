import { api, UI } from './ui.js';

// Pin / unpin a project card from the index grid.
// Card itself is an <a>, so we stop propagation on the pin button click.
document.querySelectorAll('.card[data-project-id] [data-action="toggle-pin"]').forEach(btn => {
  btn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    const card = btn.closest('.card');
    const id = card?.dataset.projectId;
    if (!id) return;
    btn.disabled = true;
    try {
      const res = await api('/api/projects/' + id + '/pin', { method: 'POST' });
      const pinned = !!res.pinned;
      btn.classList.toggle('is-on', pinned);
      card.classList.toggle('is-pinned', pinned);
      UI.toast(pinned ? 'Project pinned to top' : 'Project unpinned', 'success');
      // Re-render order locally: pinned cards go to the start of their grid,
      // preserving the rest of the (server-side) chronological order.
      const grid = card.closest('.cards-row');
      if (grid) {
        if (pinned) grid.prepend(card);
        else {
          // Move after the last pinned card; if none, just leave in place
          // (server will reorder on next page reload).
          const pinnedCards = grid.querySelectorAll('.card.is-pinned');
          const lastPinned = pinnedCards[pinnedCards.length - 1];
          if (lastPinned && lastPinned !== card) lastPinned.after(card);
        }
      }
    } catch {}
    finally { btn.disabled = false; }
  });
});
