(() => {
  const table=document.querySelector('.wp-term-table');
  if(!table) return;
  for(const option of document.querySelectorAll('[data-term-column]')) {
    const column=option.dataset.termColumn;
    const cells=table.querySelectorAll(`[data-term-col="${column}"]`);
    const key=`terms-${new URLSearchParams(location.search).get('taxonomy')}-${column}`;
    option.checked=localStorage.getItem(key)!=='hidden';
    const apply=()=>{cells.forEach(cell=>cell.hidden=!option.checked);localStorage.setItem(key,option.checked?'shown':'hidden');};
    apply();option.addEventListener('change',apply);
  }
  document.querySelectorAll('[data-quick-term]').forEach(button=>button.addEventListener('click',()=>{
    const row=document.getElementById(`quick-term-${button.dataset.quickTerm}`);
    document.querySelectorAll('.quick-edit-row').forEach(other=>{if(other!==row)other.hidden=true;});
    row.hidden=false;row.querySelector('input')?.focus();
  }));
  document.querySelectorAll('[data-cancel-quick]').forEach(button=>button.addEventListener('click',()=>button.closest('tr').hidden=true));
  document.getElementById('post-term-actions')?.addEventListener('submit',event=>{
    if(event.submitter?.value?.startsWith('delete:') || (event.submitter?.textContent.trim()==='Apply' && event.currentTarget.elements.action.value==='delete')) {
      if(!confirm('Delete the selected terms? Posts will remain.'))event.preventDefault();
    }
  });
})();
