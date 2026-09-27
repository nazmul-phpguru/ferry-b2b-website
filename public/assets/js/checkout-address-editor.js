(function(){
  'use strict';
  window.CheckoutAddressEditor={mount:function(o){
    var form=o.form,addresses=o.addresses,selected=addresses.length?0:-1,busy=false;
    var keys=['name','company','phone','line1','line2','city','state','postcode','country'];
    var list=form.querySelector('.checkout-addresses');
    if(!list){
      var section=document.createElement('section');section.className='checkout-card';
      section.innerHTML='<div class="checkout-card__head"><h2>Saved addresses</h2></div><div class="checkout-addresses"></div>';
      form.querySelector('.checkout-main').prepend(section);list=section.querySelector('.checkout-addresses');
    }
    var box=document.createElement('div');box.className='checkout-address-edit';
    box.innerHTML='<p>Changed details are saved as a new address. Your original address stays unchanged.</p><div><button type="button" data-save>Save as new address</button><button type="button" data-reset>Reset changes</button></div><span role="status" aria-live="polite"></span>';
    form.querySelector('.checkout-fields').after(box);
    var save=box.querySelector('[data-save]'),reset=box.querySelector('[data-reset]'),status=box.querySelector('[role=status]');
    var note=form.querySelector('.checkout-save-note');if(note)note.textContent='New addresses are also saved when you place your order. Existing matching addresses are reused.';
    function values(){var a={type:'shipping'};keys.forEach(function(k){a[k]=form.elements[k].value.trim();});return a;}
    function matches(a,b){return keys.every(function(k){return String(a[k]||'').trim().toLowerCase()===String(b[k]||'').trim().toLowerCase();});}
    function update(){
      var a=values(),found=addresses.some(function(b){return matches(a,b);});
      save.disabled=busy||found;reset.disabled=busy;
      save.textContent=found?'Address already saved':'Save as new address';
    }
    function fill(a){
      var country=form.elements.country;
      if(a.country&&!Array.from(country.options).some(function(x){return x.value===a.country;}))country.add(new Option(a.country,a.country));
      keys.forEach(function(k){form.elements[k].value=a[k]||(k==='country'?'CH':'');});
      status.textContent='';update();o.onChange();
    }
    function render(){
      list.replaceChildren();
      addresses.concat([null]).forEach(function(a,index){
        var label=document.createElement('label');label.className='checkout-address'+(selected===index||(selected===-1&&!a)?' is-selected':'');
        var radio=document.createElement('input');radio.type='radio';radio.name='saved_address';radio.value=a?String(a.id):'new';radio.checked=a?selected===index:selected===-1;
        var text=document.createElement('div'),title=document.createElement('strong'),line=document.createElement('span');line.className='checkout-address-line';
        title.textContent=a?[a.name,a.company].filter(Boolean).join(' · '):'Use a new address';
        line.textContent=a?[a.line1,a.line2,[a.postcode,a.city].filter(Boolean).join(' '),a.country].filter(Boolean).join(', '):'Enter a different delivery address below';
        label.title=title.textContent+' — '+line.textContent;text.append(title,line);label.append(radio,text);list.append(label);
        radio.onchange=function(){if(busy){render();return;}selected=a?index:-1;fill(a||{});render();};
      });
    }
    form.addEventListener('input',update);form.addEventListener('change',update);
    reset.onclick=function(){fill(selected>=0?addresses[selected]:{});};
    save.onclick=function(){
      if(busy)return;
      for(var k of keys)if(!form.elements[k].reportValidity())return;
      var a=values();busy=true;update();status.textContent='Saving address…';status.classList.remove('is-error');
      o.save(null,a).then(function(result){
        if(!form.isConnected)return;
        var index=addresses.findIndex(function(x){return String(x.id)===String(result.id);});
        if(index<0){addresses.push(Object.assign({id:result.id},a));index=addresses.length-1;}
        selected=index;render();status.textContent='Address saved. Your previous addresses are unchanged.';o.onChange();
      }).catch(function(e){if(form.isConnected){status.textContent=e.message||'Unable to save address.';status.classList.add('is-error');}}).finally(function(){busy=false;if(form.isConnected)update();});
    };
    render();if(selected>=0)fill(addresses[selected]);else update();
  }};
})();
