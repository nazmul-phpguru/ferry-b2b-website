(() => {
  const table = document.querySelector('.wp-list-table');
  if (!table) return;
  for (const option of document.querySelectorAll('[data-post-column]')) {
    const column = option.dataset.postColumn;
    const cells = table.querySelectorAll(`[data-post-col="${column}"]`);
    const key = `${document.querySelector('.wp-posts-list')?.dataset.listKind || 'posts'}-column-${column}`;
    let saved = null;
    try { saved = localStorage.getItem(key); } catch (_) {}
    option.checked = saved === 'shown' || (saved === null && !option.hasAttribute('data-default-hidden'));
    const apply = () => {
      cells.forEach(cell => { cell.hidden = !option.checked; });
      try { localStorage.setItem(key, option.checked ? 'shown' : 'hidden'); } catch (_) {}
    };
    apply(); option.addEventListener('change', apply);
  }
})();
