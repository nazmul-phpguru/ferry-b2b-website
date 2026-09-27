(function () {
  "use strict";
  const esc = value => String(value == null ? "" : value).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
  const glyph = kind => '<svg class="b2b-nav-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' + (kind === "all" ? '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>' : kind === "search" ? '<circle cx="11" cy="11" r="7"/><path d="m21 21-5-5"/>' : kind === "Parts" ? '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 1v4m0 14v4M1 12h4m14 0h4"/>' : kind === "Supplies" ? '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4 8 8 4 8-4M12 12v9"/>' : '<rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10 18h4"/>') + '</svg>';
  let ctx, categories = [], deviceModels = [];
  function descendants(parent) {
    const result = [], seen = new Set();
    function walk(id) { categories.filter(c => +c.parent_id === +id).forEach(c => { if (seen.has(c.id)) return; seen.add(c.id); result.push(c); walk(c.id); }); }
    walk(parent.id); return result;
  }
  function mountHeader(context) {
    ctx = context;
    const header = document.getElementById("site-header");
    const extras = header.querySelector(".extras-menu");
    const form = document.getElementById("search-form");
    form.className = "ferry-main-search-form";
    form.querySelector(".dgwt-wcas-sf-wrapp").className = "search-input-wrapper";
    form.querySelector(".dgwt-wcas-search-ico").className = "search-icon";
    const input = form.querySelector("input[type=search]");
    input.className = ""; input.placeholder = "Search the entire catalogue…";
    input.setAttribute("aria-label", "Search all products in the catalogue");
    input.setAttribute("aria-controls", "search-results"); input.setAttribute("aria-expanded", "false");
    const submit = form.querySelector("button[type=submit]");
    submit.className = "search-submit"; submit.textContent = "Search all";
    header.className = "app-header";
    header.innerHTML = '<div class="container header-inner"><a class="logo" href="' + ctx.url("") + '" aria-label="Home"><img src="' + ctx.base + 'assets/themes/ferry-main/logo-reference.png" alt="Ferry Telecom" width="200" height="48"></a><button type="button" class="page-search-jump">' + glyph("search") + '<span>Search all products</span></button><div class="search-bar"></div><nav class="user-nav" aria-label="Customer account"></nav></div><nav id="store-menu" class="store-mega-menu" aria-label="Catalogue menu"></nav>';
    const announcement = document.createElement("div");
    announcement.className = "test-banner";
    announcement.innerHTML = '<span>TEST ENVIRONMENT — NO REAL PAYMENTS OR EXTERNAL FULFILMENT</span>';
    header.before(announcement);
    header.querySelector(".search-bar").append(form);
    form.onsubmit = event => { event.preventDefault(); closeSearch(); ctx.search(input.value.trim()); };
    header.querySelector(".user-nav").append(extras);
    header.querySelector(".page-search-jump").onclick = () => {
      const panel = document.querySelector("[data-catalog-smart-search]");
      if (panel) panel.hidden = false;
      const search = document.querySelector("#ferryMainSearch input, #catalog-smart-search");
      if (search) { search.focus({preventScroll:true}); search.scrollIntoView({behavior:"smooth",block:"center"}); }
      else { header.classList.toggle("fm-search-visible"); input.focus(); }
    };
    Promise.all([ctx.get("/categories"),ctx.get("/models").catch(()=>[]) ]).then(([data,models]) => { categories = data; deviceModels=models||[]; renderMenu(); }).catch((error) => {
      document.getElementById("store-menu").innerHTML = '<a href="' + ctx.url("shop") + '">Browse catalogue</a>';
      console.error("Catalogue navigation failed", error);
    });
    document.addEventListener("click", e => { if (!e.target.closest("#store-menu")) closeMenus(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape") closeMenus(true); });
    setupLiveSearch();
  }
  function updateAccount(customer) {
    document.body.classList.toggle("fm-signed-in", !!customer);
    const account = document.getElementById("menu-extra-register");
    if (account) account.setAttribute("aria-label", customer ? "My account" : "Sign in or create an account");
  }
  function closeMenus(focus) {
    document.querySelectorAll("#store-menu .b2b-dropdown-overlay").forEach(x => x.hidden = true);
    document.querySelectorAll("#store-menu [data-fm-menu]").forEach(x => {
      if (focus && x.getAttribute("aria-expanded") === "true") x.focus();
      x.setAttribute("aria-expanded","false"); x.closest("li").classList.remove("open","is-open");
    });
  }
  function setupFamilySlider(overlay) {
    const rail = overlay.querySelector(".b2b-mega-families");
    const source = rail && rail.querySelector(".b2b-brand-families");
    if (!rail || !source || rail.dataset.fmSliderReady === "true") return;
    rail.dataset.fmSliderReady = "true";
    const viewport = document.createElement("div");
    viewport.className = "b2b-family-slider-viewport";
    source.before(viewport); viewport.append(source);
    const arrow = (direction,label,path) => {
      const button=document.createElement("button");
      button.type="button";button.className="b2b-family-slider-arrow b2b-family-slider-arrow--"+direction;
      button.setAttribute("aria-label",label);
      button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="'+path+'"/></svg>';
      return button;
    };
    const previous=arrow("previous","Previous categories","m15 18-6-6 6-6");
    const next=arrow("next","Next categories","m9 18 6-6-6-6");
    rail.prepend(previous);rail.append(next);
    let moving=false;
    function syncMode(){
      const isStatic=source.children.length<=7||source.scrollWidth<=viewport.clientWidth+2;
      rail.classList.toggle("is-static",isStatic);
      previous.hidden=isStatic;next.hidden=isStatic;
    }
    requestAnimationFrame(syncMode);window.addEventListener("resize",syncMode,{passive:true});
    function widthOf(item){
      const style=getComputedStyle(source);return item.getBoundingClientRect().width+(parseFloat(style.columnGap||style.gap)||0);
    }
    function finish(callback){
      let completed=false;const done=()=>{if(completed)return;completed=true;source.removeEventListener("transitionend",done);source.style.transition="none";source.style.transform="translateX(0)";callback();moving=false;};
      source.addEventListener("transitionend",done);setTimeout(done,360);
    }
    function move(direction){
      if(moving||rail.classList.contains("is-static")||source.children.length<2)return;moving=true;
      if(direction>0){
        const first=source.firstElementChild,step=widthOf(first);
        source.style.transition="transform .28s ease";source.style.transform="translateX(-"+step+"px)";
        finish(()=>source.append(first));
      }else{
        const last=source.lastElementChild;source.prepend(last);
        const step=widthOf(last);source.style.transition="none";source.style.transform="translateX(-"+step+"px)";
        requestAnimationFrame(()=>requestAnimationFrame(()=>{source.style.transition="transform .28s ease";source.style.transform="translateX(0)";}));
        finish(()=>{});
      }
    }
    previous.onclick=()=>move(-1);next.onclick=()=>move(1);
  }
  function renderMenu() {
    const roots = categories.filter(c => !+c.parent_id);
    const partLinks=["LCDs & screens","Batteries","Charging ports","Cameras","Flex cables & buttons","Speakers & audio","Adhesive & seals","Housing & rear glass","Repair tools","Cases & protection","Cables & accessories","Other parts"];
    const supplyLinks=["Repair tools","Cases & protection","Cables & accessories"];
    const menuCategory=(name,index,group)=>({id:"menu-cat-"+group+"-"+index,name,slug:"",search_term:name});
    const groups = [
      {label:"Apple",families:["iPhone","iPad","Apple Watch","MacBook"]},
      {label:"Samsung",families:["Galaxy S","Galaxy A","Galaxy Z","Galaxy Note","Galaxy M","Galaxy J","Galaxy XCover"]},
      {label:"Parts",categories:partLinks.map((name,index)=>menuCategory(name,index,"parts"))},
      {label:"Supplies",categories:supplyLinks.map((name,index)=>menuCategory(name,index,"supplies"))},
      {label:"Other brands",families:["Google Pixel","Huawei","Xiaomi","OnePlus","HONOR"]}
    ];
    const nav = document.getElementById("store-menu");
    const menuCategories=groups.flatMap(g=>g.categories||[]);
    nav.innerHTML = '<div class="container"><button type="button" class="b2b-mobile-toggle" aria-expanded="false" aria-controls="b2b-top-navigation">' + glyph("all") + '<span>Catalogue</span></button><ul id="b2b-top-navigation" class="b2b-top-nav"><li class="b2b-nav-item"><a class="b2b-nav-link b2b-nav-all" href="' + ctx.url("shop") + '">' + glyph("all") + '<span>All</span></a></li>' + groups.map((g,i) => {
      const hasFamilies = !!(g.families && g.families.length);
      return '<li class="b2b-nav-item has-dropdown"><button type="button" class="b2b-nav-link" data-fm-menu="' + i + '" aria-expanded="false" aria-controls="fm-menu-' + i + '" aria-label="Show ' + esc(g.label) + ' models">' + glyph(g.label) + '<span>' + g.label + '</span><svg class="mobile-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="m6 9 6 6 6-6"/></svg></button><div id="fm-menu-' + i + '" class="b2b-dropdown-overlay" hidden><div class="b2b-mega-layout"><div class="b2b-mega-families"><div class="b2b-brand-families active" role="tablist" aria-label="' + esc(g.label) + ' device families">' + (g.families||[]).map((fam,j) => '<div class="b2b-family-row' + (j===0?' active':'') + '"><button type="button" role="tab" aria-selected="' + (j===0?'true':'false') + '" class="b2b-family-btn' + (j===0?' active':'') + '" data-fm-family="' + esc(fam) + '">' + esc(fam) + '</button></div>').join("") + (g.categories||[]).map((c,j) => '<div class="b2b-family-row' + (!hasFamilies&&j===0?' active':'') + '"><button type="button" role="tab" aria-selected="' + (!hasFamilies&&j===0?'true':'false') + '" class="b2b-family-btn' + (!hasFamilies&&j===0?' active':'') + '" data-fm-category="' + c.id + '">' + esc(c.name) + '</button></div>').join("") + '</div></div><div class="b2b-mega-models"><div class="b2b-mega-heading"><label class="b2b-model-search-wrap"><span>Search your model</span><input type="search" class="b2b-model-search" placeholder="Enter a model name…" autocomplete="off"></label><button type="button" class="b2b-menu-close">Close</button></div><div class="b2b-models-grid active" data-fm-models></div></div></div></div></li>';
    }).join("") + '</ul></div>';
    nav.querySelector(".b2b-mobile-toggle").onclick = function () {
      const open = this.getAttribute("aria-expanded") !== "true"; this.setAttribute("aria-expanded",open);
      nav.querySelector(".b2b-top-nav").classList.toggle("mobile-open",open);
    };
    function models(overlay, family, query, categoryId) {
      const category = categories.find(c => String(c.id) === String(categoryId)) || menuCategories.find(c => String(c.id) === String(categoryId));
      const canonical = m => {
        let name=String(m.name||"").replace(/^iphone/i,"iPhone").replace(/\bSe\b/gi,"SE").replace(/\biPhone Xr\b/i,"iPhone XR").replace(/\biPhone Xs\b/i,"iPhone XS").replace(/\b(\d{2})E\b/gi,"$1e");
        let series=m.series||"Other models";
        if(m.brand==="iPhone") {
          name=name.replace(/\biPhone SE\s*\(2020\/2022\)/i,"iPhone SE (2022)").replace(/\biPhone SE\s*\(3rd Gen\)/i,"iPhone SE (2022)").replace(/\biPhone SE\s*\(2nd Gen\)/i,"iPhone SE (2020)").replace(/\biPhone SE\s*\(1st Gen\)/i,"iPhone SE (2016)");
          const generation=name.match(/iPhone\s+(\d{1,2})/i)?.[1];
          series=/\bSE\b/i.test(name)?"SE Series":/iPhone\s+X/i.test(name)?"X · XR · XS":generation&&Number(generation)<11?"Earlier models":(generation||"Other")+" Series";
        }
        return {...m,name,series};
      };
      let matches=[...new Map(deviceModels.map(canonical).map(m=>[m.name,m])).values()].filter(m => !!family && (family==='Other brands' ? (m.brand==='Other brands'||!/iPhone|iPad|MacBook|Apple Watch|Samsung Galaxy/.test(m.brand)) : m.family===family || m.brand===family || (family==='Samsung Galaxy'&&m.brand==='Samsung Galaxy')) && m.name.toLowerCase().includes(query.toLowerCase()));
      const seriesOrder={"17 Series":0,"16 Series":1,"15 Series":2,"14 Series":3,"13 Series":4,"12 Series":5,"11 Series":6,"X · XR · XS":7,"SE Series":8,"Earlier models":9,"Other models":10};
      if(family==="iPhone") matches.sort((a,b)=>{
        const seriesDiff=(seriesOrder[a.series]??99)-(seriesOrder[b.series]??99); if(seriesDiff)return seriesDiff;
        const rank=n=>/SE \(2022\)/i.test(n)?0:/SE \(2020\)/i.test(n)?1:/Pro Max/i.test(n)?0:/Pro/i.test(n)?1:/\bAir\b/i.test(n)?2:/\bPlus\b/i.test(n)?3:/\be\b/i.test(n)?0:4;
        if(a.series==="Earlier models") {
          const generation=n=>Number(n.match(/iPhone\s+(\d+)/i)?.[1]||0);
          const generationDiff=generation(b.name)-generation(a.name);
          if(generationDiff)return generationDiff;
          return (/Plus/i.test(a.name)?0:1)-(/Plus/i.test(b.name)?0:1);
        }
        if(a.series==="X · XR · XS") {
          const xRank=n=>/XS Max/i.test(n)?0:/\bXS\b/i.test(n)?1:/\bXR\b/i.test(n)?2:3;
          return xRank(a.name)-xRank(b.name);
        }
        return rank(a.name)-rank(b.name)||b.name.localeCompare(a.name,undefined,{numeric:true});
      });
      const seriesMap=new Map();
      matches.forEach(m=>{const key=m.series||'Other models';if(!seriesMap.has(key))seriesMap.set(key,[]);seriesMap.get(key).push(m);});
      const groupsHtml=[...seriesMap.entries()].map(([series,items])=>'<details class="b2b-model-series-group" open><summary><span>'+esc(series)+'</span><small>'+items.length+'</small></summary><div class="b2b-series-body"><div class="b2b-model-links">'+items.slice(0,5).map(m=>'<a class="b2b-model-link" href="'+ctx.searchUrl(m.name)+'">'+esc(m.name)+'</a>').join('')+'</div>'+(items.length>5?'<a class="b2b-series-more" href="'+ctx.searchUrl(family||query)+'">Show all '+items.length+' in '+esc(series)+'</a>':'')+'</div></details>').join('');
      const categoryUrl=category?(category.search_term?ctx.searchUrl(category.search_term):ctx.url('categories/'+category.slug)):'';
      const links=matches.length?'<div class="b2b-model-series">'+groupsHtml+'</div>':'<div class="b2b-model-links">'+(category?'<a class="b2b-model-link" href="'+categoryUrl+'">'+esc(category.name)+'</a>':'<p class="b2b-model-status">No models found. Try another search.</p>')+'</div>';
      overlay.querySelector('[data-fm-models]').innerHTML=links+(family?'<a class="b2b-family-all" href="'+ctx.searchUrl(family)+'">All parts for '+esc(family)+' <small>'+matches.length+' models</small></a>':(category?'<a class="b2b-family-all" href="'+categoryUrl+'">All parts for '+esc(category.name)+' →</a>':''));
      overlay.dataset.family=family||'';overlay.dataset.category=categoryId||'';
    }
    nav.querySelectorAll(".b2b-dropdown-overlay").forEach(overlay => {
      const first = overlay.querySelector("[data-fm-family],[data-fm-category]"); if(first) models(overlay,first.dataset.fmFamily||'',"",first.dataset.fmCategory);
      overlay.querySelectorAll("[data-fm-family],[data-fm-category]").forEach(button => button.onclick = () => {
        overlay.querySelectorAll(".b2b-family-btn").forEach(b => {b.classList.toggle("active",b===button);b.closest('.b2b-family-row')?.classList.toggle('active',b===button);b.setAttribute('aria-selected',b===button?'true':'false');});
        overlay.querySelector("input").value = ""; models(overlay,button.dataset.fmFamily||'',"",button.dataset.fmCategory);
      });
      overlay.querySelector("input").oninput = e => models(overlay,overlay.dataset.family,e.target.value,overlay.dataset.category);
      overlay.querySelector(".b2b-menu-close").onclick = () => closeMenus(true);
    });
    nav.querySelectorAll("[data-fm-menu]").forEach(button => button.onclick = e => {
      e.stopPropagation(); const open = button.getAttribute("aria-expanded") !== "true"; closeMenus();
      button.setAttribute("aria-expanded",open); button.closest("li").classList.toggle("open",open); button.closest("li").classList.toggle("is-open",open);
      const overlay=document.getElementById(button.getAttribute("aria-controls"));overlay.hidden = !open;
      if(open&&matchMedia("(min-width: 769px)").matches)overlay.style.setProperty("--fm-menu-top",nav.getBoundingClientRect().bottom+"px");
      if(open)requestAnimationFrame(()=>setupFamilySlider(overlay));
    });
    nav.addEventListener("click",e => { if(e.target.closest("a")) closeMenus(); });
  }
  let searchTimer, searchVersion = 0, searchInput, searchBox;
  function closeSearch() {
    clearTimeout(searchTimer); searchVersion++;
    if(searchBox)searchBox.remove();
    if(searchInput)searchInput.setAttribute("aria-expanded","false");
    searchBox=null;
  }
  function setupLiveSearch() {
    const selector = "#search-input, #ferryMainSearch input, #catalog-smart-search";
    document.addEventListener("input", event => {
      if (!event.target.matches(selector)) return;
      closeSearch(); searchInput=event.target;
      const query=searchInput.value.trim(), version=++searchVersion;
      if(query.length<2)return;
      searchTimer=setTimeout(async () => {
        try {
          const data=await ctx.get("/products?q="+encodeURIComponent(query)+"&per_page=8");
          if(version!==searchVersion||!searchInput.isConnected)return;
          const products=data.items||[];
          searchBox=document.createElement("div");
          searchBox.className="search-suggestions b2b-search-results fm-search-popout";
          searchBox.id="fm-search-popout"; searchBox.setAttribute("role","dialog");searchBox.setAttribute("aria-label","Search results");
          searchInput.setAttribute("aria-controls",searchBox.id);searchInput.setAttribute("aria-expanded","true");
          searchBox.innerHTML='<div class="suggestion-group-title"><span><strong>Smart matches</strong><small>'+products.length+' products</small></span><button type="button" class="search-popout-close" aria-label="Close search results">×</button></div><div class="smart-search-context"><span>Matches for <strong>'+esc(query)+'</strong></span><span>Choose quantity</span><span>Add to cart</span></div>'+
            (products.map(p => {
              const canOrder=ctx.approved()&&p.price!=null&&p.stock_status!=="outofstock", minimum=Math.max(1,Number(p.minimum_order_qty||1));
              return '<div class="b2b-suggestion"><a class="b2b-suggestion-link" data-search-option href="'+ctx.url("product/"+p.slug)+'"><img src="'+esc(p.image||ctx.placeholder)+'" alt="" width="44" height="44"><span class="b2b-suggestion-info"><strong>'+esc(p.name)+'</strong><small>'+esc(p.sku)+' · '+esc(p.variant||"")+'</small><small>'+(p.stock_status==="outofstock"?"Out of stock":esc(p.stock)+" in stock")+'</small></span></a><div class="b2b-suggestion-price">'+(ctx.approved()&&p.price!=null?ctx.money(p.price,p.currency):"Sign in for prices")+'</div><div class="b2b-suggestion-order">'+(canOrder?'<input type="number" class="b2b-qty-input" min="'+minimum+'" value="'+minimum+'" aria-label="Quantity"><button type="button" class="b2b-add btn btn-primary" data-fm-add="'+p.id+'">Add</button>':'<a href="'+ctx.url("login")+'" class="btn btn-outline btn-sm">Sign in</a>')+'</div><span class="b2b-row-feedback" aria-live="polite"></span></div>';
            }).join("")||'<p class="b2b-empty">No matching products. Try a model name or SKU.</p>')+
            '<a class="suggestion-footer" data-search-option href="'+ctx.searchUrl(query)+'">View all '+Number(data.total||0)+' results →</a>';
          document.body.append(searchBox);
          const rect=searchInput.getBoundingClientRect(), width=Math.min(1000,innerWidth-24);
          searchBox.style.width=width+"px";searchBox.style.left=Math.max(12,Math.min(rect.left,innerWidth-width-12))+"px";
          searchBox.style.top=Math.min(rect.bottom+8,innerHeight-240)+"px";
          searchBox.style.maxHeight=Math.max(220,innerHeight-rect.bottom-24)+"px";
          searchBox.querySelector(".search-popout-close").onclick=()=>{const input=searchInput;closeSearch();input.focus();};
          bindProducts(searchBox,products);
          searchBox.addEventListener("click",e=>{if(e.target.closest("a"))setTimeout(closeSearch,0);});
        }catch(error){if(version===searchVersion)ctx.notice("Search could not load. Please try again.");}
      },220);
    });
    document.addEventListener("click",e=>{if(searchBox&&!searchBox.contains(e.target)&&e.target!==searchInput)closeSearch();});
    document.addEventListener("keydown",e=>{
      if(!searchBox)return;
      if(e.key==="Escape"){const input=searchInput;closeSearch();input.focus();}
      if((e.key==="ArrowDown"||e.key==="ArrowUp")&&(e.target===searchInput||searchBox.contains(e.target))){
        const links=[...searchBox.querySelectorAll("[data-search-option]")];
        if(!links.length)return;e.preventDefault();
        const index=links.indexOf(document.activeElement);links[(index+(e.key==="ArrowDown"?1:-1)+links.length)%links.length].focus();
      }
    });
    addEventListener("resize",closeSearch);
  }
  function shopHTML(s,cats,attrs,approved) {
    const current = cats.find(c => c.slug === s.category);
    let shown = current ? cats.filter(c => +c.parent_id === +current.id) : cats.filter(c => !+c.parent_id && !/hidden from/i.test(c.name));
    if(current && !shown.length) shown = cats.filter(c => +c.parent_id === +current.parent_id);
    const part = attrs.find(a => /^(part-category|part_category|master-filter|master_filter|website-filter)$/i.test(a.slug)) || attrs.find(a => /part category|master filter|website filter/i.test(a.name));
    const quality = attrs.find(a => /quality/i.test(a.name));
    const brand = attrs.find(a => /brand/i.test(a.name));
    function field(a) {
      if(!a) return "";
      return '<div class="field"><label for="fm-' + esc(a.slug) + '">' + esc(a.name) + '</label><select class="form-control fm-attr-select" data-attribute="' + esc(a.slug) + '" id="fm-' + esc(a.slug) + '"><option value="">All ' + esc(a.name.toLowerCase()) + '</option>' + a.values.map(v => '<option value="' + esc(a.slug + ":" + v.slug) + '"' + (s.attributes.includes(a.slug+":"+v.slug)?" selected":"") + '>' + esc(v.value) + '</option>').join("") + '</select></div>';
    }
    const chips = s.attributes.map(v => '<button type="button" class="filter-chip" data-fm-remove="' + esc(v) + '">' + esc(v.split(":")[1].replace(/-/g," ")) + ' ×</button>').join("");
    return '<div class="container fm-catalog-page" data-catalog-shell><div class="catalog-breadcrumb"><a href="' + ctx.url("") + '">Home</a><span>/</span><a href="' + ctx.url("shop") + '">Catalogue</a>' + (current ? '<span>/</span><span>' + esc(current.name) + '</span>' : '') + '</div><section class="catalog-smart-search" data-catalog-smart-search hidden><div class="catalog-smart-intro"><strong>Smart Search</strong><span>Search by product, model or SKU.</span></div><form class="catalog-smart-form" id="fm-catalog-search" role="search"><div class="catalog-smart-field">' + glyph("search") + '<input type="search" id="catalog-smart-search" name="q" value="' + esc(s.q) + '" placeholder="Describe the part you need" aria-label="Search catalogue"><button type="submit">Smart Search</button></div></form></section>' +
      (!approved ? '<div class="catalog-price-notice"><span>Want to see your customer prices?</span><a href="' + ctx.url("login") + '">Sign in →</a></div>' : "") +
      '<div class="catalog-layout"><aside class="catalog-sidebar" id="shop-sidebar" aria-label="Browse and filter catalogue"><div class="catalog-sidebar-heading"><span>Catalogue</span><h2>Find the right part</h2></div><nav class="quick-categories" aria-label="Choose category"><button type="button" class="quick-category' + (!current?' active':'') + '" data-fm-category=""><span class="quick-category-thumb is-glyph">' + glyph("all") + '</span><span>All parts</span></button>' + (current?'<button type="button" class="quick-category active" data-fm-category="' + esc(current.slug) + '"><span>' + esc(current.name) + '</span></button>':"") + shown.map(c => '<button type="button" class="quick-category" data-fm-category="' + esc(c.slug) + '"><span class="quick-category-thumb">' + ctx.catMedia(c) + '</span><span>' + esc(c.name) + '</span></button>').join("") + '</nav><div class="catalog-desktop-filters"><h3>Filter products</h3><form class="discovery-filters" id="fm-filters">' + field(part) + field(brand) + '<details class="advanced-filters"><summary>More filters <small>Optional</small></summary>' + attrs.filter(a => a!==part&&a!==brand&&!/ean|hscode/i.test(a.slug)).map(field).join("") + '</details><label class="check-label"><input type="checkbox" class="stock-chk" value="instock"' + (s.stock.includes("instock")?' checked':'') + '> In stock only</label><p class="filter-auto-hint">Your selection is applied automatically.</p></form></div><div class="catalog-sidebar-active"><div class="active-filters">' + chips + '<button type="button" class="clear-filters" id="fm-clear-filters">Clear all</button></div></div></aside><section class="catalog-main" id="shop-main" aria-label="Product results"><div class="catalog-refine-row">' + field(quality) + '<button type="button" class="stock-shortcut' + (s.stock.includes("instock")?' active':'') + '" id="fm-stock-toggle" aria-pressed="' + s.stock.includes("instock") + '">In stock</button><button type="button" class="btn btn-outline" id="fm-filter-toggle">All filters</button></div><div class="fm-results-tools"><span id="result-count" aria-live="polite"></span><div class="view-toggle"><button type="button" class="view-btn' + (s.view==="list"?' active':'') + '" data-view="list">List</button><button type="button" class="view-btn' + (s.view==="grid"?' active':'') + '" data-view="grid">Grid</button></div><select id="sort-select" class="form-control sort-select" aria-label="Sort products"><option value="">In stock first</option><option value="newest">Newest</option><option value="name">Name A–Z</option><option value="price">Price low–high</option></select></div><div id="products-grid"></div><div class="pagination" id="shop-pagination"></div></section></div></div>';
  }
  function bindShop(s, update) {
    const root = document.querySelector("[data-catalog-shell]");
    root.querySelectorAll("[data-fm-category]").forEach(b => b.onclick = () => update({category:b.dataset.fmCategory,attributes:[],page:1},true));
    root.querySelectorAll(".fm-attr-select").forEach(select => select.onchange = () => {
      const selected = s.attributes.filter(v => !v.startsWith(select.dataset.attribute+":"));
      if(select.value)selected.push(select.value); update({attributes:selected,page:1});
    });
    root.querySelector("#fm-catalog-search").onsubmit = e => {e.preventDefault();update({q:e.target.elements.q.value.trim(),page:1});};
    root.querySelector("#fm-clear-filters").onclick = () => update({category:"",attributes:[],stock:[],q:"",page:1},true);
    root.querySelectorAll("[data-fm-remove]").forEach(b => b.onclick = () => update({attributes:s.attributes.filter(v => v!==b.dataset.fmRemove),page:1},true));
    root.querySelector("#fm-stock-toggle").onclick = e => {const active=!s.stock.includes("instock");e.target.classList.toggle("active",active);e.target.setAttribute("aria-pressed",active);update({stock:active?["instock"]:[],page:1});};
    root.querySelector("#fm-filter-toggle").onclick = () => {const sidebar=root.querySelector(".catalog-sidebar");sidebar.classList.toggle("fm-filters-open");sidebar.querySelector(".catalog-desktop-filters").scrollIntoView({block:"center",behavior:"smooth"});};
    root.querySelector("#fm-filters").onsubmit = e => e.preventDefault();
  }
  function productTable(items,approved) {
    const qualityLabel = p => p.quality || ((p.name.match(/(?:Original|Service Pack|Pulled OLED|Soft OLED(?:\s+(?:JK|RJ|GX))?|Refurbished(?:\s+FOG OLED)?|(?:KD|JK|RJ)\s+Incell|Incell(?:\s+(?:KD|JK|RJ))?|AMOLED|OLED(?:\s+(?:JK|RJ|GX))?)/i)||[])[0] || "Standard");
    return '<div class="b2b-products"><table class="b2b-table"><colgroup><col class="col-track-image"><col class="col-track-title"><col class="col-track-quality"><col class="col-track-price"><col class="col-track-sku"><col class="col-track-stock"><col class="col-track-action"></colgroup><thead><tr><th class="col-product" colspan="2">Product</th><th class="col-details" colspan="4">Details</th><th class="col-order">Action</th></tr></thead><tbody>' + items.map(p => {
      const available = p.stock_status !== "outofstock", qty = Math.max(1,Number(p.minimum_order_qty||p.minimum_quantity||1));
      const canOrder = approved && available && p.price != null, added=!!(ctx.inCart&&ctx.inCart(p.id));
      return '<tr class="b2b-row"><td class="col-img"><a class="b2b-img-wrap" href="' + ctx.url("product/"+p.slug) + '"><img src="' + esc(p.image||ctx.placeholder) + '" alt="' + esc(p.name) + '" width="48" height="48" loading="lazy"></a></td><td class="col-product"><div class="b2b-prod-title-line"><a class="b2b-prod-title" href="' + ctx.url("product/"+p.slug) + '" title="'+esc(p.name)+'">' + esc(p.name) + '</a><button type="button" class="b2b-product-toggle" aria-expanded="false" aria-label="Expand product details"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg></button></div>'+(p.color?'<div class="b2b-product-extra"><b>Color</b> '+esc(p.color)+'</div>':'')+'</td><td class="col-quality"><span class="b2b-quality">'+esc(qualityLabel(p))+'</span></td><td class="col-price"><div class="b2b-price">' + (approved?(p.price!=null?ctx.money(p.price,p.currency):"Unavailable"):'<a href="'+ctx.url("login")+'" class="b2b-login-link">Sign in for prices</a>') + '</div></td><td class="col-sku"><span class="b2b-sku">'+esc(p.sku||"—")+'</span></td><td class="col-stock"><div class="b2b-stock-indicator ' + (available?"b2b-stock-ok":"b2b-stock-out") + '"><span class="b2b-dot"></span>' + (available?(+p.stock>0?esc(p.stock)+" ":"")+"in stock":"Out of stock") + '</div></td><td class="col-order">' + (canOrder?'<div class="b2b-order-controls"><button type="button" class="b2b-qty-step" data-fm-qty="-1" aria-label="Decrease quantity">−</button><input type="number" class="b2b-qty-input" min="'+qty+'" value="'+qty+'" aria-label="Quantity"><button type="button" class="b2b-qty-step" data-fm-qty="1" aria-label="Increase quantity">+</button><button type="button" class="b2b-add-btn'+(added?' success':'')+'" data-fm-add="'+p.id+'" '+(added?'disabled':'')+'>'+(added?'Added <span aria-hidden="true">✓</span>':'Add to cart')+'</button></div>':approved?'':'<a class="b2b-login-link" href="'+ctx.url("login")+'">Sign in to order</a>') + '</td></tr>';
    }).join("") + '</tbody></table></div>';
  }
  function bindProducts(root,items) {
    root.querySelectorAll(".b2b-product-toggle").forEach(button => button.onclick = () => {const row=button.closest('.b2b-row'),expanded=row.classList.toggle('is-expanded');button.setAttribute('aria-expanded',expanded?'true':'false');button.setAttribute('aria-label',expanded?'Close product details':'Expand product details');});
    root.querySelectorAll("[data-fm-qty]").forEach(button => button.onclick = () => {const input=button.parentElement.querySelector(".b2b-qty-input"),min=+input.min||1;input.value=Math.max(min,(+input.value||min)+(+button.dataset.fmQty));});
    root.querySelectorAll("[data-fm-add]").forEach(button => button.onclick = () => {
      const input=button.parentElement.querySelector(".b2b-qty-input"); if(!input.reportValidity()) return;
      const product=items.find(p => +p.id===+button.dataset.fmAdd);
      if(product){
        ctx.add(product,+input.value,button);
        button.disabled=true;button.classList.add('success');button.innerHTML='Added <span aria-hidden="true">✓</span>';
      }
    });
  }
  window.FerryMain = {mountHeader,updateAccount,shopHTML,bindShop,productTable,bindProducts};
})();
