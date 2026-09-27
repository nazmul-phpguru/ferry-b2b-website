(() => {
  const table = document.querySelector('.wp-list-table');
  if (!table) return;
  for (const option of document.querySelectorAll('[data-post-column]')) {
    const column = option.dataset.postColumn;
    const cells = table.querySelectorAll(`[data-post-col="${column}"]`);
    const key = `${document.querySelector('.wp-posts-list')?.dataset.listKind || 'posts'}-column-${column}`;
    option.checked = localStorage.getItem(key) !== 'hidden';
    const apply = () => { cells.forEach(cell => { cell.hidden = !option.checked; }); localStorage.setItem(key, option.checked ? 'shown' : 'hidden'); };
    apply(); option.addEventListener('change', apply);
  }
})();
