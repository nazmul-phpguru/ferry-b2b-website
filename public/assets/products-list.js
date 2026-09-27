(() => {
  const list = document.querySelector('.wp-products-list');
  if (!list) return;

  const selectAll = list.querySelector('[data-select-all-products]');
  const productChecks = [...list.querySelectorAll('.product-select')];
  if (selectAll) {
    selectAll.addEventListener('change', () => {
      productChecks.forEach(input => { input.checked = selectAll.checked; });
      selectAll.indeterminate = false;
    });
    productChecks.forEach(input => input.addEventListener('change', () => {
      const checked = productChecks.filter(box => box.checked).length;
      selectAll.checked = checked === productChecks.length;
      selectAll.indeterminate = checked > 0 && checked < productChecks.length;
    }));
  }

  for (const button of list.querySelectorAll('[data-quick-edit]')) {
    button.addEventListener('click', () => {
      const row = document.getElementById(`quick-${button.dataset.quickEdit}`);
      if (!row) return;
      row.hidden = false;
      row.querySelector('input')?.focus();
    });
  }
  for (const button of list.querySelectorAll('[data-quick-cancel]')) {
    button.addEventListener('click', () => {
      const row = document.getElementById(`quick-${button.dataset.quickCancel}`);
      if (row) row.hidden = true;
    });
  }
  list.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const row = event.target.closest('.quick-edit-row');
    if (row) {
      row.hidden = true;
      list.querySelector(`[data-quick-edit="${row.id.slice(6)}"]`)?.focus();
    }
  });
})();
