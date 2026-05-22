const search = document.querySelector('[data-project-search]');
if (search) {
  const cards = document.querySelectorAll('.cards-row .card');
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    cards.forEach(card => {
      const name = card.querySelector('.name')?.textContent.toLowerCase() || '';
      card.style.display = !q || name.includes(q) ? '' : 'none';
    });
  });
}
