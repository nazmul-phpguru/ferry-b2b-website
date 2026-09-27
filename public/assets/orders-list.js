document.addEventListener('click',event=>{
  const toggle=event.target.closest('.order-address-toggle');
  const close=event.target.closest('.order-address-close');
  const button=toggle||close?.closest('.order-address-cell')?.querySelector('.order-address-toggle');
  if(!button)return;
  const details=button.closest('.order-address-cell').querySelector('.order-address-details');
  const open=toggle?button.getAttribute('aria-expanded')!=='true':false;
  button.setAttribute('aria-expanded',String(open));
  details.hidden=!open;
  if(close)button.focus();
});
document.querySelector('[data-select-all-orders]')?.addEventListener('change',event=>{
  document.querySelectorAll('.order-select').forEach(box=>box.checked=event.target.checked);
});
