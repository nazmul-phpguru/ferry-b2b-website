document.querySelectorAll('[data-address-edit]').forEach(button=>{
  button.addEventListener('click',()=>{
    const fields=button.closest('.order-reference-address').querySelector('.order-reference-address-fields');
    const open=fields.hidden;
    fields.hidden=!open;
    button.setAttribute('aria-expanded',String(open));
    button.textContent=open?'Close':'Edit';
  });
});
