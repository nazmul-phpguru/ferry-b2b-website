(function () {
  "use strict";

  var BASE = new URL(document.baseURI).pathname.replace(/\/+$/, "");
  if (BASE.charAt(BASE.length - 1) !== "/") BASE += "/";
  var API = BASE + "api/store";
  var THEME = (window.__THEME__ && typeof window.__THEME__ === "string") ? window.__THEME__ : "template-1";
  var CUR = "CHF";
  var PLACEHOLDER = BASE + "assets/img/no-image.svg";
  var CAT_PLACEHOLDER = BASE + "assets/img/category-default.svg";

  var state = {
    b2b: false,
    customer: null,
    customerGroup: null,
    csrf: null,
    cart: loadCart()
  };

  // Shop view state — filters live here, never in the URL.
  var shopState = null;
  var shopFilterStyle = "images";
  var shopProductsPerPage = 12;
  var shopLoadingMode = "load_more";
  var shopLoading = false;
  var shopRequestVersion = 0;
  var shopScrollObserver = null;
  var shopCats = [], shopAttrs = [];
  var catById = {}, catSlugToId = {};
  var pendingShopInit = null;
  var lastItems = [];
  var allItems = [];
  var jsonRequestCache = {};
  var marketCountries = {};
  var ISO_COUNTRY_CODES = "AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW".split(" ");
  var COMMON_COUNTRIES = ["CH","DE","AT","FR","IT","GB","US","NL","ES","BE"];

  function fullMarketCountries(serverCountries){
    serverCountries=serverCountries||{};
    var names=typeof Intl!=="undefined"&&Intl.DisplayNames?new Intl.DisplayNames(["en"],{type:"region"}):null;
    var all={};
    COMMON_COUNTRIES.concat(ISO_COUNTRY_CODES.filter(function(code){return COMMON_COUNTRIES.indexOf(code)<0;})).forEach(function(code){all[code]=serverCountries[code]||(names?names.of(code):code)||code;});
    return all;
  }

  function loadCart() {
    try { var cart=JSON.parse(localStorage.getItem("ft_cart"))||[];cart.forEach(function(item){item.image=imgUrl(item.image);});localStorage.setItem("ft_cart",JSON.stringify(cart));return cart; }
    catch (e) { return []; }
  }
  function saveCart() { localStorage.setItem("ft_cart", JSON.stringify(state.cart)); sessionStorage.removeItem("ft_checkout_key"); }

  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }
  function enc(s) { return encodeURIComponent(s); }
  function fmt(n) {
    n = parseFloat(n);
    if (isNaN(n)) return "0.00";
    return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, "'");
  }
  function money(n, currency) { return (currency || CUR) + " " + fmt(n); }

  function countryOptions(selected,countries){countries=countries||marketCountries;return Object.keys(countries||{}).map(function(code){return '<option value="'+esc(code)+'" '+(code===selected?'selected':'')+'>'+esc(countries[code])+' ('+esc(code)+')</option>';}).join('');}
  function enhanceCountrySelect(select){
    if(!select||select.dataset.select2Ready)return;select.dataset.select2Ready='1';select.classList.add('country-select-native');
    var wrap=document.createElement('div');wrap.className='country-select2';wrap.innerHTML='<button type="button" class="country-select2__control" aria-haspopup="listbox" aria-expanded="false"><span></span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg></button><div class="country-select2__dropdown" hidden><label><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5"/><path d="m13 13 4 4"/></svg><input type="search" autocomplete="off" placeholder="Search country or code" aria-label="Search countries"></label><div class="country-select2__options" role="listbox"></div></div>';select.after(wrap);select.hidden=true;
    var control=wrap.querySelector('.country-select2__control'),label=control.querySelector('span'),drop=wrap.querySelector('.country-select2__dropdown'),search=drop.querySelector('input'),options=drop.querySelector('.country-select2__options');
    function current(){var option=select.options[select.selectedIndex];label.textContent=option?option.text:'Select a country';}
    function render(){var q=search.value.trim().toLowerCase(),matches=Array.prototype.filter.call(select.options,function(option){return !q||option.text.toLowerCase().indexOf(q)>=0||option.value.toLowerCase().indexOf(q)>=0;}).slice(0,10);options.innerHTML=matches.map(function(option){return '<button type="button" role="option" data-country="'+esc(option.value)+'" aria-selected="'+(option.selected?'true':'false')+'"><span>'+esc(option.text.replace(/\s*\([A-Z]{2}\)$/,''))+'</span><small>'+esc(option.value)+'</small></button>';}).join('')||'<p class="country-select2__empty">No countries found.</p>';options.querySelectorAll('[data-country]').forEach(function(button){button.onclick=function(){select.value=button.dataset.country;current();close();select.dispatchEvent(new Event('change',{bubbles:true}));};});}
    function open(){drop.hidden=false;wrap.classList.add('open');control.setAttribute('aria-expanded','true');search.value='';render();setTimeout(function(){search.focus();},0);}
    function close(){drop.hidden=true;wrap.classList.remove('open');control.setAttribute('aria-expanded','false');}
    control.onclick=function(){drop.hidden?open():close();};search.oninput=render;search.onkeydown=function(e){if(e.key==='Escape'){close();control.focus();}if(e.key==='Enter'){var first=options.querySelector('[data-country]');if(first){e.preventDefault();first.click();}}};document.addEventListener('click',function(e){if(!wrap.contains(e.target))close();});
    select._countryRefresh=function(){current();render();};current();
  }
  function loadMarketCountries(){return getJSON(API+'/settings/markets').then(function(data){marketCountries=fullMarketCountries(data.countries);var select=document.getElementById('reg-country'),selected=select&&select.value||data.default_country||'CH';if(select){select.innerHTML=countryOptions(selected);if(!select.value&&select.options.length)select.selectedIndex=0;enhanceCountrySelect(select);}return data;});}
  function gatewayArtwork(id){var art={stripe:'<svg viewBox="0 0 78 38" aria-hidden="true"><rect width="78" height="38" rx="10" fill="#635bff"/><path fill="#fff" d="M31 14.5c0-1.1.9-1.6 2.4-1.6 2.1 0 4.7.7 6.8 1.8V8.4a18 18 0 0 0-6.8-1.2c-5.6 0-9.3 2.9-9.3 7.8 0 7.6 10.5 6.4 10.5 9.6 0 1.2-1.1 1.7-2.7 1.7-2.3 0-5.2-1-7.6-2.4v6.5c2.6 1.1 5.1 1.7 7.6 1.7 5.8 0 9.7-2.8 9.7-7.9 0-8.2-10.6-6.8-10.6-9.7Z"/></svg>',paypal:'<svg viewBox="0 0 78 38" aria-hidden="true"><rect width="78" height="38" rx="10" fill="#fff"/><path fill="#003087" d="M25 6h10c6.4 0 8.7 3.2 8.1 7.3-.9 6.1-4.8 9.5-10.7 9.5h-2.6l-1.2 7.8h-7.1L25 6Z"/><path fill="#009cde" d="M32.8 11.6h8.6c4.6 0 6.8 2.5 6.3 6-.8 5.4-4.4 8.2-9.2 8.2H36l-.8 4.8h-5.8l3.4-19Z" opacity=".9"/></svg>',bank_transfer:'<svg viewBox="0 0 78 38" aria-hidden="true"><rect width="78" height="38" rx="10" fill="#edf5f1"/><path d="M21 15h36M25 15v13m9-13v13m10-13v13m9-13v13M20 30h38M39 6l19 8H20l19-8Z" fill="none" stroke="#315e4e" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',invoice:'<svg viewBox="0 0 78 38" aria-hidden="true"><rect width="78" height="38" rx="10" fill="#f3f0fb"/><path d="M29 6h15l7 7v18H29V6Zm15 0v8h7M34 20h12m-12 6h9" fill="none" stroke="#645590" stroke-width="2.2" stroke-linejoin="round"/></svg>',qr_invoice:'<svg viewBox="0 0 78 38" aria-hidden="true"><rect width="78" height="38" rx="10" fill="#f3f7f5"/><g fill="#173029"><path d="M23 7h10v10H23V7Zm2 2v6h6V9h-6Zm20-2h10v10H45V7Zm2 2v6h6V9h-6ZM23 20h10v10H23V20Zm2 2v6h6v-6h-6Z"/><path d="M37 8h4v4h-4zm0 7h5v5h-5zm7 6h4v4h-4zm6-2h6v5h-6zm-13 6h5v5h-5zm9 3h4v3h-4zm7-2h3v5h-3z"/></g></svg>'};return art[id]||'';}

  function gatewayMedia(id,details) {
    details=details||{};var width=Math.max(24,Math.min(240,Number(details.image_width)||78)),height=Math.max(18,Math.min(120,Number(details.image_height)||38));
    var style="width:"+width+"px;height:"+height+"px;"+String(details.image_css||"");
    var content=details.image?'<img src="'+esc(imgUrl(details.image))+'" alt="" loading="eager" decoding="async">':gatewayArtwork(id);
    return '<span class="checkout-gateway__mark checkout-gateway__mark--'+id+'" style="'+esc(style)+'">'+content+'</span>';
  }

  function U(path) {
    var url = BASE + (path.charAt(0) === "/" ? path.slice(1) : path);
    if (new URLSearchParams(location.search).has("theme")) {
      var preview = new URL(url, location.origin); preview.searchParams.set("theme", THEME);
      return preview.pathname + preview.search + preview.hash;
    }
    return url;
  }
  function navigationHref(url) { url=String(url||""); return url.charAt(0)==="/" ? U(url.slice(1)) : url; }
  function renderNavigationItems(items) {
    return (items||[]).map(function(item){
      var children=item.children||[],classes=[];
      if(item.css_class)classes=String(item.css_class).split(/\s+/).filter(Boolean);
      if(children.length)classes.push("nav-item-has-children");
      var external=item.target==="_blank"?' target="_blank" rel="noopener noreferrer"':'';
      return '<li'+(classes.length?' class="'+esc(classes.join(' '))+'"':'')+'><a href="'+esc(navigationHref(item.url))+'"'+external+(item.description?' title="'+esc(item.description)+'"':'')+'>'+esc(item.label)+'</a>'+(children.length?'<ul class="sub-menu">'+renderNavigationItems(children)+'</ul>':'')+'</li>';
    }).join("");
  }
  function loadPrimaryNavigation() {
    if (THEME === "template-6") return Promise.resolve();
    return getJSON(API+"/navigation/primary").then(function(data){var list=document.querySelector(".primary-nav .nav-links");if(list&&data.items&&data.items.length)list.innerHTML=renderNavigationItems(data.items);}).catch(function(){});
  }

  // Normal-PHP fix: resolve DB image paths (/img/x, img/x) to BASE + assets/img/x
  function imgUrl(src) {
    if (!src) return PLACEHOLDER;
    if (/^(https?:|data:|blob:)/.test(src)) return src;
    var p = String(src).replace(/^\/+/, "");
    if (/^assets\/img\/product\?id=\d+$/i.test(p)) return BASE + p.replace(/^assets\/img\/product/i,"media/product");
    if (/^product\?id=\d+$/i.test(p)) return BASE + "media/" + p;
    if (p.indexOf("assets/") === 0) return BASE + p;
    if (p.indexOf("uploads/") === 0) return BASE + p;
    if (p.indexOf("media/") === 0) return BASE + p;
    if (p.indexOf("img/") === 0) return BASE + "assets/" + p;
    // bare filename or unknown path -> assets/img/<file>
    var file = p.split("/").pop();
    return BASE + "assets/img/" + file;
  }
  function normItem(o) {
    if (o && typeof o === "object" && typeof o.image === "string") o.image = imgUrl(o.image);
    return o;
  }
  function normData(d) {
    if (!d) return d;
    if (d.items && d.items.length) d.items.forEach(normItem);
    if (d.product) normItem(d.product);
    if (d.popular && d.popular.length) d.popular.forEach(normItem);
    if (d.news && d.news.length) d.news.forEach(normItem);
    if (d.media && d.media.length) d.media.forEach(function (m) {
      if (m && typeof m.file_path === "string") m.file_path = imgUrl(m.file_path);
    });
    return d;
  }
  // Global fallback: any broken product image -> placeholder (covers screen.svg/battery.svg/tool.svg missing files)
  document.addEventListener("error", function (e) {
    var t = e.target;
    if (t && t.tagName === "IMG" && t.src !== PLACEHOLDER && t.getAttribute("data-fbk") !== "1") {
      t.setAttribute("data-fbk", "1");
      t.src = PLACEHOLDER;
    }
  }, true);

  function stockHtml(status, stock) {
    if (status === "outofstock") return '<div class="stock out-of-stock">Out of stock</div>';
    if (stock >= 25) return '<div class="stock">25+ stock</div>';
    return '<div class="stock">' + esc(stock) + ' stock</div>';
  }
  function colorSwatch(slug) {
    var key = String(slug || "").toLowerCase();
    var colors = { black:"#171717", white:"#fff", red:"#ef4444", blue:"#3b55e6", green:"#35a853", yellow:"#facc15", orange:"#fb923c", coral:"#ff8b45", silver:"#d6d9dc", grey:"#9ca3af", gray:"#9ca3af", "space-grey":"#8b9095", gold:"#d5a942", purple:"#8b5cf6", violet:"#7c3aed", pink:"#ec88a8", brown:"#8b5e3c", bronze:"#a87945", graphite:"#555b61", transparent:"rgba(210,225,240,.38)" };
    if (colors[key]) return colors[key];
    if (key.indexOf("black") >= 0) return "#171717";
    if (key.indexOf("blue") >= 0 || key === "navy") return "#3b55e6";
    if (key.indexOf("green") >= 0) return "#35a853";
    if (key.indexOf("red") >= 0 || key.indexOf("burgundy") >= 0) return "#ef4444";
    if (key.indexOf("silver") >= 0 || key.indexOf("grey") >= 0 || key.indexOf("gray") >= 0) return "#a7adb4";
    if (key.indexOf("gold") >= 0) return "#d5a942";
    return "#dbe4ef";
  }
  function priceHtml(p, opts) {
    opts = opts || {};
    if (!state.b2b) {
      return '<div class="guest-card-price" aria-label="Login for price"><span>Login for price</span></div>';
    }
    var v = (opts.wholesale != null) ? opts.wholesale : p;
    if (v == null) return '<div class="price">—</div>';
    return '<div class="price">' + money(v, opts.currency) + "</div>";
  }

  function getJSON(url) {
    var cacheable = url === API + "/categories" || url === API + "/attributes" || url === API + "/home";
    if (cacheable && jsonRequestCache[url]) return jsonRequestCache[url];
    var request = fetch(url, { credentials: "same-origin" }).then(function (r) {
      return r.json().then(function (j) {
        if (!j.ok) throw new Error(j.error || ("HTTP " + r.status));
        return normData(j.data);
      });
    });
    if (cacheable) {
      jsonRequestCache[url] = request.catch(function (error) {
        delete jsonRequestCache[url];
        throw error;
      });
      return jsonRequestCache[url];
    }
    return request;
  }
  function postJSON(url, data) {
    var headers={"Content-Type":"application/json"};if(state.csrf)headers["X-CSRF-Token"]=state.csrf;
    return fetch(url, { method:"POST", credentials:"same-origin", headers:headers, body:JSON.stringify(data || {}) }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.error || ("HTTP " + r.status)); return j.data; });
    });
  }
  function sendJSON(method,url,data) {
    var headers={"Content-Type":"application/json"};if(state.csrf)headers["X-CSRF-Token"]=state.csrf;
    return fetch(url,{method:method,credentials:"same-origin",headers:headers,body:JSON.stringify(data||{})}).then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok)throw new Error(j.error||("HTTP "+r.status));return j.data;});});
  }
  function applyCustomerSession(data) {
    state.customer = data && data.authenticated ? data.customer : null;
    state.b2b = !!(data && data.authenticated && data.approved);
    state.customerGroup = state.b2b && state.customer ? state.customer.customer_group : null;
    state.csrf = data && data.csrf ? data.csrf : null;
    document.body.classList.toggle("is-guest", !state.b2b);
    if (THEME === "template-6" && window.FerryMain) FerryMain.updateAccount(state.customer);
    if (!state.customer && state.cart.length) { state.cart = []; saveCart(); }
  }

  function pushUrl(url) {
    if (url === location.pathname + location.search + location.hash) { route(); return; }
    history.pushState({}, "", url);
    route();
  }
  function searchSlug(value) {
    return String(value || "").trim().toLocaleLowerCase().normalize("NFKD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  }
  function searchPath(value) { var slug=searchSlug(value); return slug ? U("shop/"+slug) : U("shop"); }
  function searchTermFromPath(value) { return decodeURIComponent(String(value||"")).replace(/-+/g," ").trim(); }

  var app = document.getElementById("app");
  function loading() { app.innerHTML = '<div class="loading"><div class="spinner"></div>Loading…</div>'; }

  function footerHtml() {
    if (THEME === "template-6") {
      return '<footer class="site-footer ferry-main-footer"><div class="footer-service-strip"><div class="container footer-service-grid">' +
        '<div class="footer-service-item">' + icon("box") + '<span><strong>Current stock</strong><small>See availability before ordering</small></span></div>' +
        '<div class="footer-service-item">' + icon("tag") + '<span><strong>Business pricing</strong><small>Pricing for approved customers</small></span></div>' +
        '<div class="footer-service-item">' + icon("shield") + '<span><strong>Secure account</strong><small>Orders and addresses in one place</small></span></div>' +
        '</div></div><div class="container footer-widgets footer-grid">' +
        '<div class="footer-col footer-brand"><img src="' + BASE + 'assets/themes/ferry-main/logo.svg" alt="Ferry Telecom" class="footer-logo" loading="lazy"><p>The standard for professional repairers. Precision, reliability and stock ready to ship.</p><p class="footer-business-note">Wholesale parts for professional repair and resale businesses.</p></div>' +
        '<div class="footer-col footer-links"><h4>Shop</h4><a href="' + U("shop") + '">Catalogue</a><a href="' + U("categories/apple-parts") + '">Parts</a><a href="' + U("categories") + '">Categories</a></div>' +
        '<div class="footer-col footer-links"><h4>Account</h4><a href="' + U("account") + '">Sign in</a><a href="' + U("account") + '">Request an account</a><a href="' + U("cart") + '">Cart</a></div>' +
        '<div class="footer-col footer-links"><h4>Orders &amp; service</h4><a href="' + U("account") + '">Order history</a><a href="' + U("account") + '">Returns</a><a href="' + U("account") + '">Delivery addresses</a></div>' +
        '<div class="footer-col footer-links"><h4>Customer service</h4><a href="' + U("shipping-and-returns") + '">Shipping &amp; Returns</a><a href="' + U("terms-and-conditions") + '">Terms &amp; Conditions</a><a href="' + U("privacy-policy") + '">Privacy Policy</a></div>' +
        '</div><div class="footer-bottom"><div class="container footer-bottom-inner"><span>© ' + new Date().getFullYear() + ' Ferry Telecom. All rights reserved.</span><span>B2B wholesale for professional repairers</span></div></div></footer>';
    }
    return '<footer class="site-footer">' +
      '<div class="container footer-widgets">' +
      '<div class="footer-col"><h4>Customer Service</h4><ul>' +
      '<li><a href="' + U("shop") + '">Shop</a></li>' +
      '<li><a href="' + U("account") + '">My Account</a></li>' +
      '<li><a href="' + U("cart") + '">Cart</a></li></ul></div>' +
      '<div class="footer-col"><h4>About Ferrytelecom</h4><p>Your B2B wholesale partner for mobile phone parts, accessories and repair equipment. Serving Switzerland &amp; Luxembourg.</p></div>' +
      '<div class="footer-col"><h4>Contact</h4><p>Wholesale enquiries &amp; support.<br>SELL screens: <a href="https://sellscreens.ferrytelecom.com/" target="_blank" rel="noopener">sellscreens.ferrytelecom.com</a></p></div>' +
      '</div><div class="footer-bottom"><div class="container">© ' + new Date().getFullYear() + ' Ferrytelecom. All rights reserved.</div></div></footer>';
  }

  function breadcrumb(trail) {
    var h = '<a href="' + U("") + '">Home</a>';
    trail.forEach(function (t) {
      h += ' / ' + (t.href ? '<a href="' + t.href + '">' + esc(t.label) + "</a>" : "<span>" + esc(t.label) + "</span>");
    });
    return '<nav class="breadcrumb" aria-label="Breadcrumb">' + h + "</nav>";
  }

  /* ---------------- ROUTER ---------------- */
  function parseRoute() {
    var pn = location.pathname;
    if (pn.indexOf(BASE) === 0) pn = pn.slice(BASE.length);
    pn = pn.replace(/^\/+/, "").replace(/\/+$/, "");
    var query = {};
    if (location.search) {
      var sp = new URLSearchParams(location.search);
      sp.forEach(function (v, k) {
        if (k.slice(-2) === "[]") { k = k.slice(0, -2); (query[k] = query[k] || []).push(v); }
        else query[k] = v;
      });
    }
    return { path: pn, query: query };
  }
  function updateSeo(path) {
    var origin = location.hostname === "localhost" || location.hostname === "127.0.0.1" ? "https://ferrytelecom.com" : location.origin;
    var cleanPath = String(path || "").replace(/^\/+|\/+$/g, "");
    var label = cleanPath.split("/").pop().replace(/-/g, " ").replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    var title = "Wholesale Mobile Phone Parts Switzerland | Ferry Telecom";
    var description = "Shop wholesale mobile phone parts, screens, batteries, charging components, accessories and repair equipment from Ferry Telecom Switzerland.";
    if (cleanPath === "shop" || cleanPath.indexOf("shop/") === 0) { title = cleanPath === "shop" ? "Shop Mobile Phone Parts | Ferry Telecom" : label + " Parts | Ferry Telecom"; description = cleanPath === "shop" ? "Browse wholesale mobile phone repair parts, accessories and tools for professional repair businesses." : "Shop wholesale " + label + " replacement parts, components and repair accessories from Ferry Telecom Switzerland."; }
    else if (cleanPath === "categories") { title = "Mobile Phone Part Categories | Ferry Telecom"; description = "Browse Ferry Telecom mobile phone parts and repair accessories by category."; }
    else if (cleanPath.indexOf("categories/") === 0) { title = label + " Parts | Ferry Telecom"; description = "Shop " + label + " parts, components and repair accessories from Ferry Telecom Switzerland."; }
    else if (cleanPath.indexOf("product/") === 0) { title = label + " | Ferry Telecom"; description = "View product details, compatibility and availability for " + label + " at Ferry Telecom."; }
    else if (cleanPath === "account" || cleanPath === "cart" || cleanPath === "checkout") { title = (cleanPath === "cart" ? "Shopping Cart" : cleanPath === "checkout" ? "Secure Checkout" : "My Account") + " | Ferry Telecom"; }
    document.title = title;
    var canonical = origin + "/" + (cleanPath ? cleanPath + "/" : "");
    function content(selector, value) { var node = document.querySelector(selector); if (node) node.setAttribute("content", value); }
    var canonicalNode = document.querySelector('link[rel="canonical"]'); if (canonicalNode) canonicalNode.href = canonical;
    content('meta[name="description"]', description); content('meta[property="og:title"]', title); content('meta[property="og:description"]', description); content('meta[property="og:url"]', canonical); content('meta[name="twitter:title"]', title); content('meta[name="twitter:description"]', description);
    content('meta[name="robots"]', cleanPath === "account" || cleanPath === "cart" || cleanPath === "checkout" ? "noindex,nofollow" : "index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1");
  }
  function route() {
    shopRequestVersion++;
    shopLoading = false;
    closeDrawer();
    closeAllPanels();
    var r = parseRoute();
    var p = r.path;
    document.body.setAttribute("data-store-route", p === "" ? "home" : p.split("/")[0]);
    updateSeo(p);
    document.body.classList.toggle("home", p === "" || p === "home");
    Array.prototype.forEach.call(document.querySelectorAll(".nav-links a"), function (a) {
      var href = a.getAttribute("href");
      var hp = href === "" ? "" : href.replace(/^\/+/, "");
      a.classList.toggle("active", hp === p || (hp !== "" && p.indexOf(hp) === 0));
    });
    if (p === "" || p === "home") renderStorefrontHome();
    else if (p === "shop") { var legacySearch=r.query.q||r.query.s||""; if(legacySearch)history.replaceState({},"",searchPath(legacySearch)); enterShop({q:legacySearch}); }
    else if (p.indexOf("shop/") === 0) enterShop({q:searchTermFromPath(p.slice("shop/".length))});
    else if (p.indexOf("categories/") === 0) enterShop({ category: decodeURIComponent(p.slice("categories/".length)) });
    else if (p === "categories") renderCategories();
    else if (p.indexOf("product/") === 0) renderProduct(decodeURIComponent(p.slice("product/".length)));
    else if (p === "blog") renderCmsList();
    else if (p.indexOf("blog/") === 0) renderCmsItem(decodeURIComponent(p.slice("blog/".length)));
    else if (p === "cart") renderCart();
    else if (p === "checkout") renderCheckout(r.query);
    else if (p === "account") renderAccount();
    else if (p === "register") renderAuthPage("register");
    else if (p === "login") renderAuthPage("login");
    else if (p === "newsletter-confirm" || p === "newsletter-unsubscribe") renderNewsletterResult(p,r.query);
    else renderCmsItem(decodeURIComponent(p));
    window.scrollTo(0, 0);
  }

  function renderNewsletterResult(path,query){var action=path==='newsletter-confirm'?'confirm':'unsubscribe',title=action==='confirm'?'Confirming subscription':'Unsubscribing';app.innerHTML='<div class="container checkout-result"><h1>'+title+'…</h1><p>Please wait while we securely update your preference.</p></div>'+footerHtml();getJSON(API+'/newsletter/'+action+'?token='+enc(query.token||'')).then(function(result){app.innerHTML='<div class="container checkout-result"><h1>'+(action==='confirm'?'Subscription confirmed':'Preference updated')+'</h1><p>'+esc(result.message)+'</p><a class="btn" href="'+U('')+'">Return home</a></div>'+footerHtml();}).catch(function(error){app.innerHTML='<div class="container checkout-result checkout-result--error"><h1>Unable to update preference</h1><p>'+esc(error.message)+'</p><a class="btn" href="'+U('')+'">Return home</a></div>'+footerHtml();});}

  function renderCmsList(){loading();getJSON(API+"/content-items?type=post&per_page=12").then(function(data){var cards=data.items.map(function(p){return '<article class="cms-public-card">'+(p.featured_image?'<a href="'+U('blog/'+p.slug)+'"><img src="'+esc(imgUrl(p.featured_image))+'" alt="'+esc(p.featured_alt||p.title)+'" loading="lazy"></a>':'')+'<div><small>'+esc((p.published_at||'').slice(0,10))+'</small><h2><a href="'+U('blog/'+p.slug)+'">'+esc(p.title)+'</a></h2><p>'+esc(p.excerpt||'')+'</p><a href="'+U('blog/'+p.slug)+'">Read article →</a></div></article>';}).join('');app.innerHTML='<div class="container cms-public"><nav class="breadcrumb"><a href="'+U('')+'">Home</a> / <span>Blog</span></nav><header><h1>Insights &amp; updates</h1></header><div class="cms-public-grid">'+(cards||'<p>No posts published yet.</p>')+'</div></div>'+footerHtml();}).catch(function(e){app.innerHTML='<div class="container"><h1>Unable to load posts</h1><p>'+esc(e.message)+'</p></div>'+footerHtml();});}
  function renderCmsItem(slug){loading();getJSON(API+"/content-items/"+enc(slug)).then(function(data){var p=data.post;document.title=(p.seo_title||p.title)+' | Ferry Telecom';var desc=document.querySelector('meta[name="description"]');if(desc)desc.content=p.seo_description||p.excerpt||'';app.innerHTML='<article class="container cms-public cms-public-single"><nav class="breadcrumb"><a href="'+U('')+'">Home</a> / '+(p.type==='post'?'<a href="'+U('blog')+'">Blog</a> / ':'')+'<span>'+esc(p.title)+'</span></nav>'+(p.featured_image?'<img class="cms-public-hero" src="'+esc(imgUrl(p.featured_image))+'" alt="'+esc(p.featured_alt||p.title)+'">':'')+'<header><h1>'+esc(p.title)+'</h1>'+(p.published_at?'<time>'+esc(p.published_at.slice(0,10))+'</time>':'')+'</header><div class="cms-public-content">'+p.content+'</div></article>'+footerHtml();}).catch(function(){app.innerHTML='<div class="container checkout-result checkout-result--error"><h1>Page not found</h1><p>The requested page is unavailable.</p><a class="btn" href="'+U('')+'">Return home</a></div>'+footerHtml();});}

  /* ---------------- HEADER ---------------- */
  function buildNav() {
    var toggle = document.getElementById("menu-toggle");
    var menu = document.getElementById("menu-dropdown");
    var viewport = document.getElementById("slide-menu-viewport");
    var panel = document.getElementById("menu-list");
    var title = document.getElementById("slide-menu-title");
    var back = document.getElementById("slide-menu-back");
    var close = document.getElementById("slide-menu-close");
    if (!toggle || !menu || !viewport || !panel || !title || !back || !close) return;

    var byId = {};
    var children = {};
    var history = [];
    var activeParent = null;
    var animating = false;

    function parentKey(id) { return id === null ? "root" : String(id); }
    function childList(parentId) { return children[parentKey(parentId)] || []; }
    function panelMarkup(parentId) {
      var items = childList(parentId);
      if (!items.length) return '<div class="slide-menu-empty">No subcategories found.</div>';
      return '<ul class="slide-menu-list">' + items.map(function (cat) {
        var hasChildren = childList(cat.id).length > 0;
        return '<li class="slide-menu-item' + (hasChildren ? ' has-children' : '') + '">' +
          '<a class="slide-menu-link" href="' + U("categories/" + cat.slug) + '">' + esc(cat.name) + '</a>' +
          (hasChildren ? '<button class="slide-menu-next" type="button" data-category-id="' + cat.id + '" aria-label="Open ' + esc(cat.name) + ' subcategories"><span aria-hidden="true">&#8250;</span></button>' : '<span aria-hidden="true"></span>') +
          '</li>';
      }).join("") + '</ul>';
    }
    function updateHeading(parentId) {
      var current = parentId === null ? null : byId[String(parentId)];
      title.textContent = current ? current.name : "All Categories";
      var atRoot = history.length === 0;
      back.classList.toggle("is-hidden", atRoot);
      back.setAttribute("aria-hidden", atRoot ? "true" : "false");
    }
    function showPanel(parentId, goingBack) {
      if (animating || parentId === activeParent) return;
      animating = true;
      var oldPanel = viewport.querySelector(".slide-menu-panel.is-active");
      var nextPanel = document.createElement("div");
      nextPanel.className = "slide-menu-panel" + (goingBack ? " from-left" : "");
      nextPanel.innerHTML = panelMarkup(parentId);
      viewport.appendChild(nextPanel);
      updateHeading(parentId);
      nextPanel.offsetWidth;
      requestAnimationFrame(function () {
        nextPanel.classList.add("is-active");
        nextPanel.classList.remove("from-left");
        if (oldPanel) {
          if (goingBack) oldPanel.style.transform = "translateX(100%)";
          else oldPanel.classList.add("to-left");
        }
      });
      setTimeout(function () {
        if (oldPanel) oldPanel.remove();
        nextPanel.id = "menu-list";
        activeParent = parentId;
        animating = false;
        var first = nextPanel.querySelector("a,button");
        if (first) first.focus({ preventScroll: true });
      }, 320);
    }
    function openMenu() {
      menu.classList.add("open");
      menu.setAttribute("aria-hidden", "false");
      toggle.setAttribute("aria-expanded", "true");
      document.body.classList.add("mobile-menu-open");
      setTimeout(function () {
        var first = menu.querySelector(".slide-menu-link,.slide-menu-next");
        if (first) first.focus({ preventScroll: true });
      }, 220);
    }
    function closeMenu() {
      menu.classList.remove("open");
      menu.setAttribute("aria-hidden", "true");
      toggle.setAttribute("aria-expanded", "false");
      document.body.classList.remove("mobile-menu-open");
    }

    toggle.addEventListener("click", function (e) {
      e.stopPropagation();
      if (menu.classList.contains("open")) closeMenu(); else openMenu();
    });
    close.addEventListener("click", function () { closeMenu(); toggle.focus(); });
    back.addEventListener("click", function () {
      if (history.length) showPanel(history.pop(), true);
    });
    viewport.addEventListener("click", function (e) {
      var next = e.target.closest(".slide-menu-next");
      if (next) {
        e.preventDefault();
        history.push(activeParent);
        showPanel(parseInt(next.getAttribute("data-category-id"), 10), false);
        return;
      }
      if (e.target.closest(".slide-menu-link")) closeMenu();
    });
    document.addEventListener("click", function (e) {
      if (menu.classList.contains("open") && !menu.contains(e.target) && !toggle.contains(e.target)) closeMenu();
    });
    document.addEventListener("keydown", function (e) {
      if (!menu.classList.contains("open")) return;
      if (e.key === "Escape") { e.preventDefault(); closeMenu(); toggle.focus(); }
      if (e.key === "ArrowLeft" && history.length) { e.preventDefault(); showPanel(history.pop(), true); }
    });

    getJSON(API + "/categories").then(function (data) {
      var categories = Array.isArray(data) ? data : (data.items || []);
      categories.forEach(function (cat) { byId[String(cat.id)] = cat; });
      categories.forEach(function (cat) {
        var parentId = cat.parent_id === null || Number(cat.parent_id) === 0 || !byId[String(cat.parent_id)] ? null : Number(cat.parent_id);
        (children[parentKey(parentId)] = children[parentKey(parentId)] || []).push(cat);
      });
      Object.keys(children).forEach(function (key) {
        children[key].sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
      });
      panel.innerHTML = panelMarkup(null);
      activeParent = null;
      updateHeading(null);
    }).catch(function () {
      panel.innerHTML = '<div class="slide-menu-error">Categories could not be loaded. Please try again.</div>';
    });
  }

  function setupSearch() {
    var form = document.getElementById("search-form");
    var input = document.getElementById("search-input");
    var box = document.getElementById("search-results");
    var t;
    var searchSequence = 0;
    var categoryPromise = getJSON(API + "/categories").then(function (data) {
      return Array.isArray(data) ? data : (data.items || []);
    }).catch(function () { return []; });

    input.addEventListener("input", function () {
      clearTimeout(t);
      searchSequence += 1;
      var requestSequence = searchSequence;
      var q = input.value.trim();
      if (q.length < 2) { box.classList.remove("open"); box.innerHTML = ""; return; }
      t = setTimeout(function () {
        Promise.all([getJSON(API + "/products?q=" + enc(q) + "&page=1"), categoryPromise]).then(function (results) {
          if (requestSequence !== searchSequence || input.value.trim() !== q) return;
          var products = (results[0].items || []).slice(0, 8);
          var needle = q.toLocaleLowerCase();
          var categories = results[1].filter(function (c) {
            return String(c.name || "").toLocaleLowerCase().indexOf(needle) !== -1 || String(c.slug || "").toLocaleLowerCase().indexOf(needle) !== -1;
          }).sort(function (a, b) {
            function relevance(c) {
              var name = String(c.name || "").trim().toLocaleLowerCase();
              var slug = String(c.slug || "").trim().toLocaleLowerCase();
              if (name === needle || slug === needle) return 0;
              if (name.indexOf(needle) === 0 || slug.indexOf(needle) === 0) return 1;
              if (name.indexOf(" " + needle) !== -1 || name.indexOf("-" + needle) !== -1) return 2;
              return 3;
            }
            var score = relevance(a) - relevance(b);
            if (score) return score;
            function modelNumber(c) {
              var name = String(c.name || "").toLocaleLowerCase();
              var tail = name.slice(Math.max(0, name.indexOf(needle) + needle.length));
              var match = tail.match(/\d+/);
              return match ? parseInt(match[0], 10) : -1;
            }
            var model = modelNumber(b) - modelNumber(a);
            if (model) return model;
            var length = String(a.name || "").length - String(b.name || "").length;
            return length || String(a.name || "").localeCompare(String(b.name || ""));
          }).slice(0, 10);
          if (!products.length && !categories.length) { box.innerHTML = '<div class="sr-empty">No matching products or categories</div>'; box.classList.add("open"); return; }
          var productHtml = "";
          products.forEach(function (p) {
            productHtml += '<a class="sr-item" href="' + U("product/" + p.slug) + '">' +
              '<img src="' + esc(p.image || PLACEHOLDER) + '" alt="">' +
              '<span class="sr-copy"><span class="sr-name">' + esc(p.name) + "</span>" +
              '<span class="sr-sku">' + esc(p.sku) + "</span></span></a>";
          });
          var categoryHtml = "";
          categories.forEach(function (c) {
            categoryHtml += '<a class="sr-category" href="' + U("categories/" + c.slug) + '">' + esc(c.name) + '</a>';
          });
          box.innerHTML = '<div class="sr-layout"><section class="sr-column sr-products"><div class="sr-heading"><span>Products</span><b>' + products.length + '</b></div><div class="sr-scroll">' + (productHtml || '<div class="sr-section-empty">No matching products</div>') + '</div><a class="sr-view" href="' + searchPath(q) + '">View all product results <span>→</span></a></section>' +
            '<section class="sr-column sr-categories"><div class="sr-heading"><span>Categories</span><b>' + categories.length + '</b></div><div class="sr-scroll">' + (categoryHtml || '<div class="sr-section-empty">No matching categories</div>') + '</div></section></div>';
          box.classList.add("open");
        }).catch(function () { box.classList.remove("open"); });
      }, 220);
    });

    document.addEventListener("click", function (e) {
      if (!form.contains(e.target)) box.classList.remove("open");
    });
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var q = input.value.trim();
      box.classList.remove("open");
      pendingShopInit = null;
      pushUrl(searchPath(q));
    });
  }

  /* ---------------- CART ---------------- */
  function toastCartMessage(message) {
    var notice = document.getElementById("store-notice");
    if (!notice) {
      notice = document.createElement("div");
      notice.id = "store-notice";
      notice.className = "store-notice";
      notice.setAttribute("role", "status");
      notice.setAttribute("aria-live", "polite");
      document.body.appendChild(notice);
    }
    notice.textContent = message;
    notice.classList.remove("is-visible");
    window.clearTimeout(notice._hideTimer);
    requestAnimationFrame(function () { notice.classList.add("is-visible"); });
    notice._hideTimer = window.setTimeout(function () { notice.classList.remove("is-visible"); }, 2600);
  }
  function mcItemPrice(i, qty) {
    if (!state.b2b) return "";
    if (i.price == null) return '<span class="mc-price-loading">' + (i._priceResolved ? 'Price unavailable' : 'Loading price…') + '</span>';
    return money(i.price * qty,i.currency);
  }
  function mcSubtotal(v) {
    if (!state.b2b) return '<a class="mc-login" href="' + U("account") + '">Login for price</a>';
    return money(v);
  }
  function renderCartHeader() {
    var panelState = {};
    ["mini-cart", "mini-cart-2"].forEach(function (id) {
      var panel = document.getElementById(id);
      var scroller = panel && panel.querySelector(".mc-items");
      panelState[id] = { open: !!(panel && panel.classList.contains("open")), scrollTop: scroller ? scroller.scrollTop : 0 };
    });
    var count = state.cart.reduce(function (s, i) { return s + i.qty; }, 0);
    var sub = state.cart.reduce(function (s, i) { return s + (i.price != null ? i.price * i.qty : 0); }, 0);
    ["cart-count", "cart-count-2"].forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = count; });
    var head = '<div class="mc-head"><span>Shopping Cart</span><b>' + count + " item" + (count === 1 ? "" : "s") + '</b><button type="button" class="mc-close" aria-label="Close cart">&times;</button></div>';
    var body = "", foot = "";
    if (!state.cart.length) {
      body = '<div class="mc-empty"><span class="mc-empty__ic">🛒</span>No products in the cart yet.</div>';
    } else {
      state.cart.forEach(function (i) {
        body += '<div class="mc-item"><img src="' + esc(i.image || PLACEHOLDER) + '" alt="">' +
          '<div class="mc-info"><span class="mc-name">' + esc(i.name) + '</span>' +
          '<div class="mc-line"><span class="mc-qty"><button type="button" data-mc-minus="' + i.id + '" aria-label="Decrease quantity">−</button><input type="number" min="1" value="' + i.qty + '" data-mc-qty="' + i.id + '" aria-label="Quantity"><button type="button" data-mc-plus="' + i.id + '" aria-label="Increase quantity">+</button></span><span class="mc-meta">' + mcItemPrice(i, i.qty) + '</span></div></div>' +
          '<button class="mc-remove" data-rm="' + i.id + '" title="Remove" aria-label="Remove">&times;</button></div>';
      });
      foot = '<div class="mc-foot"><div class="mc-sub"><span>Subtotal</span><b>' + mcSubtotal(sub) + '</b></div>' +
        '<div class="mc-actions"><a class="btn" href="' + U("cart") + '">View Cart</a>' +
        '<a class="btn btn-primary" href="' + U("checkout") + '">Checkout</a></div></div>';
    }
    var h = head + '<div class="mc-items">' + body + '</div><div class="mc-more" hidden></div>' + foot;
    ["mini-cart-inner", "mini-cart-inner-2"].forEach(function (id) {
      var el = document.getElementById(id); if (!el) return;
      el.innerHTML = h;
      var closeButton = el.querySelector(".mc-close");
      if (closeButton) closeButton.addEventListener("click", function () { closeAllPanels(); });
      Array.prototype.forEach.call(el.querySelectorAll("[data-rm]"), function (b) {
        b.addEventListener("click", function (event) { event.preventDefault(); event.stopPropagation(); removeFromCart(+b.getAttribute("data-rm"), b); });
      });
      Array.prototype.forEach.call(el.querySelectorAll("[data-mc-minus]"), function (b) { b.addEventListener("click", function (event) { event.preventDefault(); event.stopPropagation(); var it=state.cart.find(function(x){return x.id===+b.getAttribute("data-mc-minus");}); if(it) updateQty(it.id,Math.max(1,it.qty-1)); }); });
      Array.prototype.forEach.call(el.querySelectorAll("[data-mc-plus]"), function (b) { b.addEventListener("click", function (event) { event.preventDefault(); event.stopPropagation(); var it=state.cart.find(function(x){return x.id===+b.getAttribute("data-mc-plus");}); if(it) updateQty(it.id,it.qty+1); }); });
      Array.prototype.forEach.call(el.querySelectorAll("[data-mc-qty]"), function (input) { input.addEventListener("change", function (event) { event.stopPropagation(); updateQty(+input.getAttribute("data-mc-qty"),Math.max(1,+input.value||1)); }); });
      Array.prototype.forEach.call(el.querySelectorAll(".mc-actions a"), function (a) {
        a.addEventListener("click", function () { closeAllPanels(); });
      });
      setupMiniCartScroll(el);
      var panel = el.closest(".mini-cart-content");
      var saved = panel && panelState[panel.id];
      if (panel && saved) {
        panel.classList.toggle("open", saved.open);
        var scroller = el.querySelector(".mc-items");
        if (scroller) scroller.scrollTop = saved.scrollTop;
      }
    });
    syncCartButtons();
  }

  function setupMiniCartScroll(root) {
    var scroller = root.querySelector(".mc-items");
    var status = root.querySelector(".mc-more");
    if (!scroller || !status || !state.cart.length) return;
    function update() {
      var items = scroller.querySelectorAll(".mc-item");
      if (scroller.scrollHeight <= scroller.clientHeight + 2) {
        status.hidden = true;
        return;
      }
      var viewportBottom = scroller.getBoundingClientRect().bottom;
      var below = 0;
      Array.prototype.forEach.call(items, function (item) {
        if (item.getBoundingClientRect().bottom > viewportBottom + 2) below += 1;
      });
      status.hidden = false;
      status.textContent = below > 0 ? "+" + below + " more item(s) in cart" : "No more items";
    }
    scroller.addEventListener("scroll", update, { passive: true });
    requestAnimationFrame(update);
  }

  function syncCartButtons() {
    var inCart = {};
    state.cart.forEach(function (item) { inCart[String(item.id)] = true; });
    Array.prototype.forEach.call(document.querySelectorAll("button[data-add],button[data-fm-add]"), function (button) {
      if (!button.hasAttribute("data-stock-disabled")) button.setAttribute("data-stock-disabled", button.hasAttribute("data-fm-add") ? "0" : (button.disabled ? "1" : "0"));
      if (!button.getAttribute("data-cart-label")) button.setAttribute("data-cart-label", button.hasAttribute("data-fm-add") ? "Add to cart" : (button.textContent.trim() || "Add to cart"));
      if (!state.b2b) {
        button.disabled = true; button.hidden = true; button.classList.add("login-required");
        return;
      }
      button.hidden = false;
      if (button.getAttribute("data-stock-disabled") === "1") return;
      button.disabled = false;
      button.classList.remove("login-required");
      var productId = button.getAttribute("data-add") || button.getAttribute("data-fm-add");
      var added = !!inCart[String(productId)];
      button.classList.toggle("is-added", added);
      button.classList.toggle("success", added);
      button.disabled = added;
      button.setAttribute("aria-label", added ? "Added to cart" : button.getAttribute("data-cart-label"));
      button.innerHTML = added ? '<span>Added</span><span class="added-check" aria-hidden="true">&#10003;</span>' : '<span>' + esc(button.getAttribute("data-cart-label")) + '</span>';
    });
  }

  function openDrawer() { document.getElementById("cart-drawer").classList.add("open"); document.getElementById("overlay").classList.add("open"); }
  function closeDrawer() { document.getElementById("cart-drawer").classList.remove("open"); document.getElementById("overlay").classList.remove("open"); }
  function visibleCartTarget() {
    var targets = [document.getElementById("icon-cart-contents-2"), document.getElementById("icon-cart-contents")];
    return targets.find(function (el) {
      if (!el) return false;
      var rect = el.getBoundingClientRect();
      var style = window.getComputedStyle(el);
      return rect.width > 0 && rect.height > 0 && style.visibility !== "hidden" && style.display !== "none";
    }) || null;
  }
  function flyToCart(product, sourceEl) {
    var target = visibleCartTarget();
    if (!target || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    var sourceImage = sourceEl && sourceEl.closest(".product,.single-product,.th-product,.th-hero-card,.t4-product,.t5-product");
    sourceImage = sourceImage && sourceImage.querySelector("img");
    var startRect = sourceImage ? sourceImage.getBoundingClientRect() : (sourceEl ? sourceEl.getBoundingClientRect() : null);
    if (!startRect) return;
    var flyer = document.createElement("img");
    flyer.className = "cart-fly-image";
    flyer.src = (sourceImage && sourceImage.currentSrc) || (sourceImage && sourceImage.src) || product.image || PLACEHOLDER;
    flyer.alt = "";
    flyer.style.left = startRect.left + "px";
    flyer.style.top = startRect.top + "px";
    flyer.style.width = Math.max(34, Math.min(startRect.width, 86)) + "px";
    flyer.style.height = Math.max(34, Math.min(startRect.height, 86)) + "px";
    document.body.appendChild(flyer);
    var targetRect = target.getBoundingClientRect();
    requestAnimationFrame(function () {
      flyer.style.left = (targetRect.left + targetRect.width / 2 - 12) + "px";
      flyer.style.top = (targetRect.top + targetRect.height / 2 - 12) + "px";
      flyer.style.width = "24px";
      flyer.style.height = "24px";
      flyer.style.opacity = ".18";
      flyer.style.transform = "rotate(10deg) scale(.72)";
    });
    setTimeout(function () {
      flyer.remove();
      target.classList.add("cart-received");
      setTimeout(function () { target.classList.remove("cart-received"); }, 480);
    }, 760);
  }
  function renderDrawer() {
    var body = document.getElementById("cart-drawer-body");
    var foot = document.getElementById("cart-drawer-foot");
    if (!state.cart.length) {
      body.innerHTML = '<div class="mc-empty">Your cart is empty.</div>';
      foot.innerHTML = "";
      return;
    }
    var h = "";
    state.cart.forEach(function (i) {
      h += '<div class="mc-item"><img src="' + esc(i.image || PLACEHOLDER) + '" alt=""><span><span class="mc-name">' + esc(i.name) +
        "</span><br><span class=\"mc-price\">" + mcItemPrice(i, i.qty) +
        "</span><br><button class=\"link-remove\" data-rm=\"" + i.id + "\">Remove</button></span></div>";
    });
    body.innerHTML = h;
    var total = state.cart.reduce(function (s, i) { return s + (i.price != null ? i.price * i.qty : 0); }, 0);
    foot.innerHTML = '<div class="mc-foot" style="padding:0 0 10px"><span>Subtotal</span><span>' + mcSubtotal(total) +
      '</span></div><a class="btn btn-primary btn-block" href="' + U("checkout") + '">Checkout</a>';
    Array.prototype.forEach.call(body.querySelectorAll("[data-rm]"), function (b) {
      b.addEventListener("click", function () { removeFromCart(+b.getAttribute("data-rm")); });
    });
  }

  function numericPrice(value) {
    if (value === null || value === undefined || value === "") return null;
    var parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
  }
  function addToCart(p, qty, sourceEl) {
    if (!state.b2b) { openLogin("login"); return; }
    qty = qty || 1;
    var ex = state.cart.find(function (i) { return i.id === p.id; });
    if (ex) ex.qty += qty;
    else state.cart.push({ id: p.id, slug: p.slug, name: p.name, image: p.image, sku: p.sku, price: (state.b2b ? numericPrice(p.price) : null), currency:p.currency||CUR, qty: qty });
    saveCart(); renderCartHeader(); renderDrawer(); flyToCart(p, sourceEl);
  }
  function removeFromCart(id, sourceEl) {
    state.cart = state.cart.filter(function (i) { return i.id !== id; });
    saveCart(); renderCartHeader(); renderDrawer();
    if (parseRoute().path === "cart") renderCart();
    if (state.b2b && state.cart.some(function (i) { return i.price == null; })) refreshCartPrices();
  }
  function updateQty(id, qty) {
    var it = state.cart.find(function (i) { return i.id === id; });
    if (!it) return;
    it.qty = Math.max(1, qty | 0);
    saveCart(); renderCartHeader(); renderDrawer();
    if (parseRoute().path === "cart") renderCart();
    if (state.b2b && it.price == null) refreshCartPrices();
  }

  /* ---------------- WISHLIST ---------------- */
  function loadWishlist() { try { var items=JSON.parse(localStorage.getItem("ft_wishlist"))||[];items.forEach(function(item){item.image=imgUrl(item.image);});localStorage.setItem("ft_wishlist",JSON.stringify(items));return items; } catch (e) { return []; } }
  function saveWishlist(w) { localStorage.setItem("ft_wishlist", JSON.stringify(w)); }
  function inWishlist(id) { return loadWishlist().some(function (x) { return x.id === id; }); }
  function updateWishlistCount() { var c = loadWishlist().length; ["wishlist-count", "wishlist-count-2"].forEach(function (id) { var el = document.getElementById(id); if (el) el.textContent = c; }); }
  function updateWishlistHearts() {
    Array.prototype.forEach.call(document.querySelectorAll("[data-wl]"), function (b) {
      b.classList.toggle("active", inWishlist(+b.getAttribute("data-wl")));
    });
  }
  function addToWishlist(item) { var w = loadWishlist(); if (!w.some(function (x) { return x.id === item.id; })) w.push(item); saveWishlist(w); updateWishlistCount(); updateWishlistHearts(); renderWishlist(); }
  function removeFromWishlist(id) { saveWishlist(loadWishlist().filter(function (x) { return x.id !== id; })); updateWishlistCount(); updateWishlistHearts(); renderWishlist(); }
  function toggleWishlist(item) { if (inWishlist(item.id)) removeFromWishlist(item.id); else addToWishlist(item); }
  function renderWishlistInto(panel) {
    if (!panel) return;
    var w = loadWishlist();
    if (!w.length) { panel.innerHTML = '<div class="wl-empty">Your wishlist is empty.</div>'; return; }
    var h = "";
    w.forEach(function (i) {
      h += '<div class="wl-item"><img src="' + esc(i.image || PLACEHOLDER) + '" alt=""><div class="wl-info"><span class="wl-name">' + esc(i.name) +
        '</span><span class="wl-price">' + (state.b2b ? (i.price != null ? money(i.price) : "Login for price") : '<a class="mc-login" href="' + U("account") + '">Login for price</a>') + '</span></div>' +
        '<div class="wl-actions"><button class="wl-add" data-wl-add="' + i.id + '" title="Add to cart"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></button>' +
        '<button class="wl-remove" data-wl-rm="' + i.id + '" title="Remove"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path></svg></button></div></div>';
    });
    h += '<div class="wl-foot"><a class="btn btn-primary btn-block" href="' + U("shop") + '">Browse products</a></div>';
    panel.innerHTML = h;
    Array.prototype.forEach.call(panel.querySelectorAll("[data-wl-add]"), function (b) {
      b.addEventListener("click", function () {
        var id = +b.getAttribute("data-wl-add");
        var it = loadWishlist().find(function (x) { return x.id === id; });
        if (it) { addToCart({ id: it.id, slug: it.slug, name: it.name, image: it.image, sku: it.sku, price: it.price }, 1, b); removeFromWishlist(id); }
      });
    });
    Array.prototype.forEach.call(panel.querySelectorAll("[data-wl-rm]"), function (b) {
      b.addEventListener("click", function () { removeFromWishlist(+b.getAttribute("data-wl-rm")); });
    });
  }
  function renderWishlist() { renderWishlistInto(document.getElementById("wishlist-panel")); }
  function closeAllPanels(except) {
    ["mini-cart", "mini-cart-2", "account-menu", "account-menu-2", "wishlist-panel", "wishlist-panel-2"].forEach(function (id) {
      if (id !== except) { var el = document.getElementById(id); if (el) el.classList.remove("open"); }
    });
  }
  function togglePanel(id) {
    var el = document.getElementById(id); if (!el) return;
    var open = el.classList.toggle("open");
    closeAllPanels(open ? id : null);
  }

  /* ---------------- PRODUCT CARD ---------------- */
  function productCard(p) {
    var out = (p.stock_status === "outofstock");
    var img = p.image || PLACEHOLDER;
    var cartButton = state.b2b
      ? '<button class="button" data-add="' + p.id + '" data-slug="' + esc(p.slug) + '" ' + (out ? "disabled" : "") + '>' + (out ? "Out of stock" : "<span>Add to cart</span>") + "</button>"
      : '<button class="button guest-card-add" type="button" disabled>Add to cart</button>';
    return '<div class="product">' +
      '<div class="mf-product-thumbnail">' +
      '<a href="' + U("product/" + p.slug) + '"><img src="' + esc(img) + '" alt="' + esc(p.name) + '"></a>' +
      "</div>" +
      '<div class="mf-product-details">' +
      '<div class="mf-product-content"><div class="product-title-cell"><h2><a href="' + U("product/" + p.slug) + '">' + esc(p.name) + "</a></h2>" +
      (p.color ? '<div class="product-color"><i style="--swatch:' + esc(colorSwatch(p.color_slug)) + '"></i>' + esc(p.color) + '</div>' : '') + '</div>' +
      '<div class="product-variant" aria-label="Product variant">' + esc(p.variant || "") + '</div>' +
      '<div class="sku">SKU: ' + esc(p.sku) + "</div>" +
      stockHtml(p.stock_status, p.stock) +
      priceHtml(p.price,{currency:p.currency}) +
      "</div>" +
      '<div class="footer-button">' + cartButton + "</div>" +
      "</div></div>";
  }

  function bindAddButtons(root) {
    root = root || app;
    Array.prototype.forEach.call(root.querySelectorAll("[data-add]"), function (b) {
      if (b.__bound) return;
      b.__bound = true;
      b.addEventListener("click", function (e) {
        e.stopPropagation();
        var data = { id: +b.getAttribute("data-add"), slug: b.getAttribute("data-slug") || "", name: "", image: "", sku: "", price: null };
        if (b.hasAttribute("data-name")) {
          data.name = b.getAttribute("data-name");
          data.image = b.getAttribute("data-img") || "";
          data.sku = b.getAttribute("data-sku") || "";
        } else {
          var card = b.closest(".product");
          data.name = card.querySelector("h2 a").textContent;
          data.image = card.querySelector("img").src;
          data.sku = card.querySelector(".sku").textContent.replace("SKU: ", "");
        }
        addToCart(data, 1, b);
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll("[data-wl]"), function (b) {
      if (b.__bound) return;
      b.__bound = true;
      b.addEventListener("click", function (e) {
        e.stopPropagation(); e.preventDefault();
        var data = { id: +b.getAttribute("data-wl"), slug: b.getAttribute("data-slug") || "", name: "", image: "", sku: "", price: null };
        if (b.hasAttribute("data-name")) {
          data.name = b.getAttribute("data-name");
          data.image = b.getAttribute("data-img") || "";
          data.sku = b.getAttribute("data-sku") || "";
        } else {
          var card = b.closest(".product");
          data.name = card.querySelector("h2 a").textContent;
          data.image = card.querySelector("img").src;
          data.sku = card.querySelector(".sku").textContent.replace("SKU: ", "");
        }
        toggleWishlist(data);
      });
    });
    syncCartButtons();
  }

  /* ---------------- CATEGORY ART (inline SVG, instant load, keyword-relevant) ---------------- */
  var CAT_COLORS = { apple:"#23282d", samsung:"#1f4fd8", huawei:"#cf0a2c", xiaomi:"#ff6900", pixel:"#34a853", google:"#4285f4", oppo:"#1ba784", sony:"#111111", nokia:"#124191", motorola:"#5c9c00", generic:"#4887f4" };
  function catColor(s, n) {
    s = (s + " " + n).toLowerCase();
    if (s.indexOf("apple") >= 0) return CAT_COLORS.apple;
    if (s.indexOf("samsung") >= 0) return CAT_COLORS.samsung;
    if (s.indexOf("huawei") >= 0 || s.indexOf("p-series") >= 0 || s.indexOf("p series") >= 0) return CAT_COLORS.huawei;
    if (s.indexOf("xiaomi") >= 0 || s.indexOf("redmi") >= 0) return CAT_COLORS.xiaomi;
    if (s.indexOf("pixel") >= 0) return CAT_COLORS.pixel;
    if (s.indexOf("google") >= 0) return CAT_COLORS.google;
    if (s.indexOf("oppo") >= 0) return CAT_COLORS.oppo;
    if (s.indexOf("sony") >= 0) return CAT_COLORS.sony;
    if (s.indexOf("nokia") >= 0) return CAT_COLORS.nokia;
    if (s.indexOf("motorola") >= 0 || s.indexOf("moto") >= 0) return CAT_COLORS.motorola;
    return CAT_COLORS.generic;
  }
  function catType(s, n) {
    s = (s + " " + n).toLowerCase();
    if (s.indexOf("batter") >= 0) return "battery";
    if (s.indexOf("screen") >= 0 || s.indexOf("display") >= 0 || s.indexOf("lcd") >= 0) return "screen";
    if (s.indexOf("camera") >= 0) return "camera";
    if (s.indexOf("speaker") >= 0) return "speaker";
    if (s.indexOf("charg") >= 0 || s.indexOf("cable") >= 0 || s.indexOf("usb") >= 0 || s.indexOf("adapter") >= 0) return "cable";
    if (s.indexOf("case") >= 0 || s.indexOf("cover") >= 0) return "case";
    if (s.indexOf("tool") >= 0 || s.indexOf("screw") >= 0) return "tool";
    if (s.indexOf("lamp") >= 0 || s.indexOf("light") >= 0) return "lamp";
    if (s.indexOf("laptop") >= 0 || s.indexOf("computer") >= 0) return "laptop";
    if (s.indexOf("watch") >= 0 || s.indexOf("band") >= 0) return "watch";
    if (s.indexOf("headphone") >= 0 || s.indexOf("earphone") >= 0 || s.indexOf("earbud") >= 0) return "headphone";
    if (s.indexOf("chip") >= 0 || s.indexOf("board") >= 0) return "chip";
    if (s.indexOf("tablet") >= 0 || s.indexOf("ipad") >= 0) return "tablet";
    if (s.indexOf("device") >= 0 || s.indexOf("gadget") >= 0) return "gadget";
    if (s.indexOf("acces") >= 0 || s.indexOf("accessor") >= 0) return "cable";
    if (s.indexOf("multimedia") >= 0) return "laptop";
    if (s.indexOf("phone") >= 0 || s.indexOf("series") >= 0 || s.indexOf("-parts") >= 0) return "phone";
    return "phone";
  }
  var CAT_ICONS = {
    phone: '<rect x="42" y="28" width="36" height="64" rx="7"/><line x1="54" y1="36" x2="66" y2="36"/><circle cx="60" cy="84" r="3"/>',
    tablet: '<rect x="34" y="26" width="52" height="68" rx="7"/><circle cx="60" cy="86" r="2.5"/>',
    laptop: '<rect x="32" y="40" width="56" height="34" rx="3"/><line x1="26" y1="80" x2="94" y2="80"/><line x1="42" y1="74" x2="78" y2="74"/>',
    watch: '<rect x="46" y="34" width="28" height="52" rx="8"/><line x1="46" y1="44" x2="38" y2="38"/><line x1="74" y1="44" x2="82" y2="38"/><line x1="46" y1="76" x2="38" y2="82"/><line x1="74" y1="76" x2="82" y2="82"/><circle cx="60" cy="60" r="9"/>',
    battery: '<rect x="34" y="46" width="44" height="28" rx="4"/><rect x="78" y="54" width="6" height="12" rx="2"/><path d="M61 51 L52 64 H60 L57 75"/>',
    screen: '<rect x="40" y="32" width="40" height="46" rx="3"/><line x1="52" y1="84" x2="68" y2="84"/><line x1="48" y1="88" x2="72" y2="88"/>',
    camera: '<rect x="38" y="44" width="44" height="32" rx="5"/><circle cx="60" cy="60" r="9"/><circle cx="78" cy="52" r="2.5"/>',
    speaker: '<rect x="42" y="34" width="36" height="52" rx="6"/><circle cx="60" cy="56" r="9"/><circle cx="60" cy="76" r="3"/>',
    cable: '<rect x="42" y="48" width="16" height="28" rx="3"/><path d="M50 48 C50 30 74 30 74 50 C74 66 60 66 60 82"/>',
    case: '<rect x="44" y="30" width="32" height="60" rx="7"/><rect x="50" y="36" width="20" height="48" rx="4"/>',
    tool: '<line x1="44" y1="86" x2="70" y2="60"/><path d="M66 56 l10 -10 6 6 -10 10 z"/>',
    lamp: '<path d="M46 44 h28 l8 22 h-44 z"/><line x1="60" y1="66" x2="60" y2="84"/><line x1="48" y1="84" x2="72" y2="84"/>',
    chip: '<rect x="44" y="44" width="32" height="32" rx="4"/><line x1="52" y1="44" x2="52" y2="36"/><line x1="68" y1="44" x2="68" y2="36"/><line x1="44" y1="52" x2="36" y2="52"/><line x1="44" y1="68" x2="36" y2="68"/><line x1="76" y1="52" x2="84" y2="52"/><line x1="76" y1="68" x2="84" y2="68"/><line x1="52" y1="76" x2="52" y2="84"/><line x1="68" y1="76" x2="68" y2="84"/>',
    headphone: '<path d="M40 64 v-6 a20 20 0 0 1 40 0 v6"/><rect x="34" y="62" width="12" height="20" rx="5"/><rect x="74" y="62" width="12" height="20" rx="5"/>',
    gadget: '<rect x="40" y="42" width="40" height="36" rx="5"/><circle cx="60" cy="60" r="6"/><line x1="40" y1="72" x2="80" y2="72"/>'
  };
  function categoryImage(slug, name) {
    var c = catColor(slug, name), t = catType(slug, name);
    var icon = CAT_ICONS[t] || CAT_ICONS.phone;
    return '<svg class="cat-art" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' + esc(name) + '">' +
      '<rect width="120" height="120" rx="16" fill="' + c + '" fill-opacity="0.10"/>' +
      '<g fill="none" stroke="' + c + '" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">' + icon + '</g></svg>';
  }

  function catImgUrl(c) {
    if (!c || !c.image) return "";
    return (/^(https?:|data:)/.test(c.image)) ? c.image : BASE + "/" + c.image.replace(/^\/+/, "");
  }
  function catMedia(c) {
    var u = catImgUrl(c);
    return u ? '<img src="' + esc(u) + '" alt="' + esc(c.name) + '" loading="lazy">' : categoryImage(c.slug, c.name);
  }

  /* ---------------- HOME ---------------- */
  function makeSlider(track) {
    if (!track) return;
    var originals = Array.prototype.slice.call(track.children);
    var n = originals.length;
    if (n < 2) return;
    var viewport = track.parentElement;
    var wrap = viewport.parentElement;
    function gap() {
      var cs = getComputedStyle(track);
      var g = parseFloat(cs.columnGap || cs.gap || "0");
      return isNaN(g) ? 0 : g;
    }
    function step() { return originals[0].getBoundingClientRect().width + gap(); }
    function perView() {
      var vw = viewport.getBoundingClientRect().width;
      return Math.max(1, Math.min(n, Math.round(vw / step())));
    }
    function buildClones() {
      Array.prototype.slice.call(track.querySelectorAll(".slider-clone")).forEach(function (c) { c.remove(); });
      var vw = viewport.getBoundingClientRect().width;
      var g = gap();
      var approx = (originals[0].getBoundingClientRect().width || 200) + g;
      var pv = Math.max(1, Math.min(n, Math.round(vw / approx)));
      var cardW = Math.max(120, (vw - (pv - 1) * g) / pv);
      var i, c;
      for (i = 0; i < pv; i++) { c = originals[n - pv + i].cloneNode(true); c.classList.add("slider-clone"); track.appendChild(c); }
      for (i = 0; i < pv; i++) { c = originals[i].cloneNode(true); c.classList.add("slider-clone"); track.insertBefore(c, track.firstChild); }
      Array.prototype.forEach.call(track.children, function (s) { s.style.width = cardW + "px"; });
      bindAddButtons(track);
    }
    var index = perView();
    function apply(animated) {
      track.style.transition = animated ? "transform .5s cubic-bezier(.22,.61,.36,1)" : "none";
      track.style.transform = "translateX(" + (-(index * step())) + "px)";
      if (!animated) { void track.offsetWidth; }
    }
    buildClones();
    index = perView();
    apply(false);
    function move(dir) { index += dir; apply(true); }
    track.addEventListener("transitionend", function (e) {
      if (e.target !== track || e.propertyName !== "transform") return;
      if (index >= n + perView()) { index = perView(); apply(false); }
      else if (index < perView()) { index = n + perView(); apply(false); }
    });
    var prev = wrap.querySelector("[data-slider-prev]");
    var next = wrap.querySelector("[data-slider-next]");
    if (prev) prev.addEventListener("click", function () { move(-1); });
    if (next) next.addEventListener("click", function () { move(1); });
    var rt;
    window.addEventListener("resize", function () {
      clearTimeout(rt);
      rt = setTimeout(function () { buildClones(); index = perView(); apply(false); }, 200);
    });
  }
  function fmtCountdown(s) {
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    var p = function (n) { return (n < 10 ? "0" : "") + n; };
    return "<span>" + p(h) + "</span>:<span>" + p(m) + "</span>:<span>" + p(sec) + "</span>";
  }
  function startHomeCountdowns() {
    ["homeCountdown", "flashCountdown"].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var total = 8 * 3600 + 23 * 60 + 45;
      el.dataset.t = total;
      el.innerHTML = fmtCountdown(total);
    });
    if (!window.__hpTimer) {
      window.__hpTimer = setInterval(function () {
        ["homeCountdown", "flashCountdown"].forEach(function (id) {
          var el = document.getElementById(id);
          if (!el) return;
          var t = parseInt(el.dataset.t, 10) - 1;
          if (t < 0) t = 8 * 3600 + 23 * 60 + 45;
          el.dataset.t = t;
          el.innerHTML = fmtCountdown(t);
        });
      }, 1000);
    }
  }
  function wireNewsletter() {
    var f = document.getElementById("newsletterForm");
    if (!f) return;
    f.addEventListener("submit", function (e) {
      e.preventDefault();
      var input=f.querySelector('input[type=email]'),email=input?input.value.trim():'';postJSON(API+'/newsletter/subscribe',{email:email}).then(function(result){f.innerHTML='<div class="hp-news__thanks">'+esc(result.message)+'</div>';}).catch(function(error){var old=f.querySelector('.hp-news__error');if(old)old.remove();f.insertAdjacentHTML('beforeend','<div class="hp-news__error">'+esc(error.message)+'</div>');});
    });
  }

  function renderStorefrontHome() {
    if (THEME === "template-6") return renderFerryMainHome();
    if (THEME === "template-2" || THEME === "template-3") return renderHomeShared(thProductCard);
    if (THEME === "template-4") return renderHomeT4();
    if (THEME === "template-5") return renderHomeT5();
    return renderHome();
  }

  /* ---------- shared add-to-cart for theme cards (data-attr driven) ---------- */
  function bindAddAttr(root) {
    root = root || app;
    Array.prototype.forEach.call(root.querySelectorAll("[data-add]"), function (b) {
      if (b.__bound) return;
      b.__bound = true;
      b.addEventListener("click", function (e) {
        e.stopPropagation();
        addToCart({
          id: +b.getAttribute("data-add"),
          slug: b.getAttribute("data-slug") || "",
          name: b.getAttribute("data-name") || "",
          image: b.getAttribute("data-img") || "",
          sku: b.getAttribute("data-sku") || "",
          price: null
        }, 1, b);
      });
    });
    Array.prototype.forEach.call(root.querySelectorAll("[data-wl]"), function (b) {
      if (b.__bound) return;
      b.__bound = true;
      b.addEventListener("click", function (e) {
        e.stopPropagation(); e.preventDefault();
        toggleWishlist({
          id: +b.getAttribute("data-wl"),
          slug: b.getAttribute("data-slug") || "",
          name: b.getAttribute("data-name") || "",
          image: b.getAttribute("data-img") || "",
          sku: b.getAttribute("data-sku") || "",
          price: null
        });
      });
    });
    syncCartButtons();
  }

  /* ---------- tabbed + slider "Best Sellers by Category" ---------- */
  function bindBestSellers(root) {
    root = root || app;
    var tabsWrap = root.querySelector(".bs-tabs");
    if (!tabsWrap) return;
    var panels = root.querySelectorAll(".bs-panel");
    function initPanel(panel) {
      var track = panel.querySelector(".slider-track");
      if (track && !track.__sliderReady) {
        makeSlider(track);
        bindAddAttr(track);
        track.__sliderReady = true;
      }
    }
    var active = root.querySelector(".bs-panel.active");
    if (active) initPanel(active);
    tabsWrap.addEventListener("click", function (e) {
      var btn = e.target.closest(".bs-tab");
      if (!btn) return;
      var key = btn.getAttribute("data-bs-tab");
      Array.prototype.forEach.call(tabsWrap.querySelectorAll(".bs-tab"), function (t) {
        t.classList.toggle("active", t === btn);
      });
      Array.prototype.forEach.call(panels, function (p) {
        var on = p.getAttribute("data-bs-panel") === key;
        p.classList.toggle("active", on);
        if (on) initPanel(p);
      });
    });
  }

    function icon(name) {
      var p = 'fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"';
      var s = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" ' + p + '>';
      var paths = {
        check: '<path d="M20 6 9 17l-5-5"/>',
        truck: '<path d="M3 7h11v8H3zM14 10h4l3 3v2h-7z"/><circle cx="7" cy="17" r="1.6"/><circle cx="17" cy="17" r="1.6"/>',
        tag: '<path d="M3 12 12 3h7v7l-9 9z"/><circle cx="15.5" cy="8.5" r="1.3"/>',
        shield: '<path d="M12 3 5 6v5c0 4 3 7 7 8 4-1 7-4 7-8V6z"/><path d="m9 12 2 2 4-4"/>',
        headset: '<path d="M4 13v-1a8 8 0 0 1 16 0v1"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/><path d="M20 19a4 4 0 0 1-4 3h-2"/>',
        bolt: '<path d="M13 2 4 14h6l-1 8 9-12h-6z"/>',
        ticket: '<path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H6a2 2 0 0 1-2-2 2 2 0 0 0 0-4z"/><path d="M12 6v12" stroke-dasharray="2 2"/>',
        gift: '<rect x="3" y="9" width="18" height="11" rx="1.5"/><path d="M3 13h18M12 9v11"/><path d="M12 9S9 3 6.5 5 9 9 12 9zM12 9s3-6 5.5-4S15 9 12 9z"/>',
        box: '<path d="M3 8 12 3l9 5v8l-9 5-9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        star: '<path d="m12 3 2.6 5.6 6 .8-4.4 4.2 1.1 6L12 17l-5.3 2.6 1.1-6L3.4 9.4l6-.8z"/>',
        card: '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h3"/>',
        mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
        arrowLeft: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>',
        refresh: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v4h-4"/>',
        phone: '<path d="M5 4h4l2 5-3 2a12 12 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        quote: '<path d="M7 7H4v6h6V7H7zm10 0h-3v6h6V7h-3z"/>'
      };
      return s + (paths[name] || '') + '</svg>';
    }

    /* ---------- SHARED THEME HOME (template-2 & template-3) ---------- */
  function thProductCard(p) {
    var out = (p.stock_status === "outofstock");
    var img = p.image || PLACEHOLDER;
    var price = state.b2b ? ((p.price != null) ? money(p.price) : "—") : '<a class="login-price-link" href="' + U("account") + '">Login for price</a>';
    return '<article class="th-product' + (out ? " is-out" : "") + '">' +
      '<button class="th-wish" type="button" aria-label="Add to wishlist" data-wl="' + p.id + '" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '" data-img="' + esc(img) + '" data-sku="' + esc(p.sku) + '">♡</button>' +
      '<div class="th-product__media"><a href="' + U("product/" + p.slug) + '"><img src="' + esc(img) + '" alt="' + esc(p.name) + '" loading="lazy" decoding="async"></a></div>' +
      '<h3 class="th-product__name"><a href="' + U("product/" + p.slug) + '">' + esc(p.name) + "</a></h3>" +
      '<p class="th-product__stock' + (out ? " out" : "") + '">' + (out ? "Out of stock" : "In stock") + "</p>" +
      '<p class="th-product__price">' + price + "</p>" +
      '<button class="th-add" type="button"' + (out ? " disabled" : "") + ' data-add="' + p.id + '" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '" data-img="' + esc(img) + '" data-sku="' + esc(p.sku) + '">' + (out ? "Out of stock" : "Add to cart") + "</button>" +
      "</article>";
  }

  /* Ferry Main is a direct port of the ferry-re storefront language.  Its
     markup deliberately keeps the original lp-* component contract while
     product, customer, price and cart data continue to come from this app. */
  function renderFerryMainHome() {
    loading();
    var partIcons = {
      screens: '<rect x="5" y="2" width="14" height="20" rx="2.5"/><line x1="10" y1="18.5" x2="14" y2="18.5"/>',
      batteries: '<rect x="2" y="7" width="16" height="10" rx="2.5"/><line x1="21.5" y1="10.5" x2="21.5" y2="13.5"/><line x1="6" y1="12" x2="13" y2="12"/>',
      charging: '<path d="M13 2 4 13.5h6.2L9.6 22 20 10.5h-6.4z"/>',
      cameras: '<path d="M22 19.5a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-11a2 2 0 0 1 2-2h3l1.7-2.5h6.6L17 6.5h3a2 2 0 0 1 2 2z"/><circle cx="12" cy="13.5" r="3.6"/>',
      tools: '<path d="M14.6 6.4a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.8-3.8a6 6 0 0 1-7.9 7.9l-6.9 6.9a2.1 2.1 0 0 1-3-3l6.9-6.9a6 6 0 0 1 7.9-7.9z"/>',
      other: '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v9M7.5 12h9"/>'
    };
    function fmIcon(slug) {
      var key = /screen|display|lcd/.test(slug) ? "screens" : /batter/.test(slug) ? "batteries" : /charg|cable|usb/.test(slug) ? "charging" : /camera/.test(slug) ? "cameras" : /tool|repair/.test(slug) ? "tools" : "other";
      return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + partIcons[key] + '</svg>';
    }
    function arrow() { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13"/><path d="m12 5 7 7-7 7"/></svg>'; }
    function productCard(p) {
      var out = p.stock_status === "outofstock";
      var image = p.image || PLACEHOLDER;
      var price = state.b2b ? (p.price != null ? money(p.price) : "Unavailable") : '<a class="lp-product-login" href="' + U("account") + '">Sign in for prices</a>';
      return '<article class="lp-product"><a class="lp-product-media" href="' + U("product/" + p.slug) + '"><img src="' + esc(image) + '" alt="' + esc(p.name) + '" loading="lazy" decoding="async"></a>' +
        '<div class="lp-product-body"><h3 class="lp-product-name"><a href="' + U("product/" + p.slug) + '">' + esc(p.name) + '</a></h3>' +
        '<div class="lp-product-details-row"><p class="lp-product-meta"><span class="lp-product-sku">' + esc(p.sku || "") + '</span></p><p class="lp-product-stock ' + (out ? "is-out" : "is-ok") + '"><span></span>' + (out ? "Out of stock" : "In stock") + '</p></div>' +
        '<div class="lp-product-foot"><span class="lp-product-price">' + price + '</span>' + (!out ? '<button type="button" class="lp-product-add" data-add="' + p.id + '" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '" data-img="' + esc(image) + '" data-sku="' + esc(p.sku || "") + '">Add to cart</button>' : "") + '</div></div></article>';
    }
    function productSection(id, kicker, title, items, slider) {
      slider = !!slider && items.length > 10;
      return '<section class="lp-section lp-product-section" data-lp-product-section="' + id + '"><header class="lp-section-head"><div><p class="lp-kicker">' + kicker + '</p><h2>' + title + '</h2></div><div class="lp-product-section-actions">' +
        (slider ? '<div class="lp-slider-controls"><button type="button" data-fm-slide="-1" aria-label="Previous products">‹</button><button type="button" data-fm-slide="1" aria-label="Next products">›</button></div>' : "") +
        '<a class="lp-section-link" href="' + U("shop") + '">View all products ' + arrow() + '</a></div></header><div class="' + (slider ? "lp-product-slider" : "lp-product-grid") + '" data-fm-track>' + items.slice(0, slider ? 20 : 10).map(productCard).join("") + '</div></section>';
    }
    getJSON(API + "/home").then(function (home) {
      var categories = (home.categories || []).filter(function (c) { return !c.parent_id; });
      var categoryCount = function (c) { return Number(c.products_count || c.product_count || c.count || 0); };
      var popular = ((home.popular || {}).items || []).filter(function (p) { return p.stock_status !== "outofstock"; });
      var newest = ((home.newest || {}).items || []).filter(function (p) { return p.stock_status !== "outofstock"; });
      var brands = home.brands || {};
      var apple = ((brands["apple-parts"] || {}).items || []).filter(function (p) { return p.stock_status !== "outofstock"; });
      var samsung = ((brands["samsung-parts"] || {}).items || []).filter(function (p) { return p.stock_status !== "outofstock"; });
      var total = Number(home.total || (home.popular || {}).total || (home.newest || {}).total || 0) || categories.reduce(function (sum, c) { return sum + categoryCount(c); }, 0);
      var stockTotal = Number(home.stock_total || 0);
      var categoryCards = categories.slice(0, 12).map(function (c) {
        var count = categoryCount(c);
        return '<a class="lp-category" href="' + U("categories/" + c.slug) + '"><span class="lp-category-icon">' + fmIcon(c.slug || "") + '</span><span class="lp-category-name">' + esc(c.name) + '</span><span class="lp-category-count">' + count.toLocaleString() + ' parts</span><span class="lp-category-go">' + arrow() + '</span></a>';
      }).join("");
      var familyCards = categories.slice(0, 6).map(function (c) { return '<a class="lp-family" href="' + U("categories/" + c.slug) + '"><span class="lp-family-name">' + esc(c.name) + '</span><span class="lp-family-count">' + categoryCount(c).toLocaleString() + ' parts</span><span class="lp-family-go">' + arrow() + '</span></a>'; }).join("");
      var modelChips = categories.slice(6, 16).map(function (c) { return '<a class="lp-model-chip" href="' + U("categories/" + c.slug) + '">' + esc(c.name) + '<small>' + categoryCount(c).toLocaleString() + '</small></a>'; }).join("");
      app.innerHTML = '<div class="lp ferry-main-home"><section class="lp-hero"><div class="lp-hero-mesh"></div><div class="lp-hero-body"><p class="lp-eyebrow">Wholesale repair parts · live stock</p><h1>The right part, <span>first time.</span></h1><p class="lp-lead">Find your model, choose the right variant and order professional repair parts directly from current stock.</p>' +
        '<form class="lp-search" id="ferryMainSearch"><div class="lp-search-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7.5"/><path d="m21 21-4.3-4.3"/></svg><input type="search" name="q" placeholder="Describe what you need in your own words" autocomplete="off"><button class="lp-search-submit" type="submit">Smart Search</button></div><p class="lp-search-hint"><strong>Write it your way.</strong> Search by product, model, SKU or category. <span>Order from results</span></p></form>' +
        '<nav class="lp-chips">' + categories.slice(0, 4).map(function (c) { return '<a href="' + U("categories/" + c.slug) + '">' + esc(c.name) + '<small>' + categoryCount(c).toLocaleString() + '</small></a>'; }).join("") + '</nav><dl class="lp-stats"><div><dt>Products</dt><dd>' + total.toLocaleString() + '</dd></div><div><dt>Categories</dt><dd>' + categories.length + '</dd></div><div><dt>' + (stockTotal ? 'In stock' : 'Browse parts') + '</dt><dd>' + (stockTotal || total).toLocaleString() + '</dd></div></dl></div></section>' +
        productSection("popular", "Popular", "Popular products", popular, true) + productSection("recent", "Recently added", "New arrivals", newest, false) +
        '<section class="lp-section"><header class="lp-section-head"><div><p class="lp-kicker">Catalogue</p><h2>Choose your part</h2></div><a class="lp-section-link" href="' + U("categories") + '">Shop catalogue ' + arrow() + '</a></header><div class="lp-grid lp-categories">' + categoryCards + '</div></section>' +
        '<section class="lp-section"><header class="lp-section-head"><div><p class="lp-kicker">Devices</p><h2>Find device parts</h2></div></header><div class="lp-devices"><nav class="lp-families">' + familyCards + '</nav><aside class="lp-models"><h3>Popular categories</h3><p class="lp-models-note">Go directly to frequently ordered product families.</p><div class="lp-model-chips">' + modelChips + '</div><a class="lp-models-all" href="' + U("categories") + '">View all categories ' + arrow() + '</a></aside></div></section>' +
        (apple.length ? productSection("iphone", "Apple", "Popular Apple parts", apple, true) : '') + (samsung.length ? productSection("samsung", "Samsung", "Popular Samsung parts", samsung, false) : '') +
        '<section class="lp-method"><h2 class="lp-sr-only">How ordering works</h2><ol class="lp-method-list"><li><span class="lp-step">01</span><h3>Search by model</h3><p>Use a product name, device, SKU or category.</p></li><li><span class="lp-step">02</span><h3>Check stock</h3><p>See availability before you place the order.</p></li><li><span class="lp-step">03</span><h3>Your own prices</h3><p>Approved accounts automatically receive role pricing.</p></li><li><span class="lp-step">04</span><h3>Order in one step</h3><p>Use a fast, secure business checkout.</p></li></ol></section>' +
        '<section class="lp-cta"><div><h2>The complete catalogue</h2><p>' + total.toLocaleString() + ' repair products in one place.</p></div><div class="lp-cta-actions"><a class="lp-btn lp-btn-primary" href="' + U("shop") + '">Shop catalogue ' + arrow() + '</a><a class="lp-btn lp-btn-ghost" href="' + U("account") + '">Sign in for prices</a></div></section></div>' + footerHtml();
      var search = document.getElementById("ferryMainSearch");
      if (search) search.addEventListener("submit", function (event) { event.preventDefault(); pendingShopInit=null; pushUrl(searchPath(search.q.value.trim())); });
      function loopProductSlider(track, direction) {
        if (!track || track.children.length < 3) return;
        track._fmSlideQueue = track._fmSlideQueue || [];
        if (track._fmSliding) { track._fmSlideQueue.push(direction); return; }
        track._fmSliding = true;
        var first = track.firstElementChild;
        var moveCount = Math.min(2, track.children.length);
        var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 0;
        var step = first.getBoundingClientRect().width + gap;
        if (direction < 0) {
          Array.prototype.slice.call(track.children, -moveCount).forEach(function (item) { track.insertBefore(item, first); });
          track.scrollLeft += step;
          requestAnimationFrame(function () { requestAnimationFrame(function () { track.scrollBy({ left: -step, behavior: "smooth" }); }); });
        } else {
          track.scrollBy({ left: step, behavior: "smooth" });
        }
        window.setTimeout(function () {
          if (direction > 0) {
            Array.prototype.slice.call(track.children, 0, moveCount).forEach(function (item) { track.appendChild(item); });
            track.scrollLeft = Math.max(0, track.scrollLeft - step);
          }
          track._fmSliding = false;
          var queuedDirection = track._fmSlideQueue.shift();
          if (queuedDirection) loopProductSlider(track, queuedDirection);
        }, 460);
      }
      Array.prototype.forEach.call(app.querySelectorAll("[data-fm-slide]"), function (button) { button.addEventListener("click", function () { var track = button.closest(".lp-product-section").querySelector("[data-fm-track]"); loopProductSlider(track, Number(button.getAttribute("data-fm-slide")) < 0 ? -1 : 1); }); });
      bindAddAttr(app);
    }).catch(function (error) { app.innerHTML = '<div class="loading">Failed to load: ' + esc(error.message) + '</div>'; });
  }

    function heroSliderHtml(featured, popularImg, newsImg) {
      var slides = [
        { cls: "th-slide--a", eyebrow: "B2B Wholesale", title: ["Wholesale Mobile Phone Parts", "&amp; Repair Equipment"], sub: "Original and tested compatible parts for Apple, Samsung, Huawei, Xiaomi and more. Bulk pricing for registered business accounts.", cta: [["Browse Catalog", U("shop"), "btn-primary"], ["Open B2B Account", U("account"), "btn-outline-light"]], micro: ["Fast Shipping", "B2B Pricing", "Quality Tested"], stats: [["10k+", "Products"], ["6+", "Brands"], ["100%", "Tested"]], img: popularImg, badge: "-25%" },
        { cls: "th-slide--b", eyebrow: "New Arrivals", title: ["Fresh Stock,", "Top Demand Models"], sub: "Stay ahead with the latest screens, batteries and boards arriving weekly. Never miss a best seller.", cta: [["Shop New Arrivals", U("shop"), "btn-primary"], ["View Brands", U("categories"), "btn-outline-light"]], micro: ["Weekly Restock", "Priority Dispatch", "Expert Picks"], stats: [["24h", "Dispatch"], ["50+", "Daily SKUs"], ["CH & LU", "Delivery"]], img: newsImg, badge: "NEW" },
        { cls: "th-slide--c", eyebrow: "Bulk Lots", title: ["Stock Up &", "Save on Volume"], sub: "Order in bulk and unlock special wholesale pricing across the entire catalog. Built for resellers.", cta: [["Get a Quote", U("account"), "btn-primary"], ["See Offers", U("shop"), "btn-outline-light"]], micro: ["Volume Discounts", "Net 30 Terms", "Dedicated Rep"], stats: [["B2B", "Rates"], ["Net 30", "Terms"], ["VIP", "Support"]], img: popularImg, badge: "B2B" }
      ];
      var dots = slides.map(function (s, i) { return '<button type="button" class="th-dot' + (i === 0 ? " active" : "") + '" data-hero-dot="' + i + '" aria-label="Go to slide ' + (i + 1) + '"></button>'; }).join("");
      var html = slides.map(function (s, i) {
        var micro = s.micro.map(function (m) { return "<span>" + icon("check") + m + "</span>"; }).join("");
        var chips = s.stats.map(function (st) { return '<div class="th-hero-chip"><strong>' + st[0] + "</strong><small>" + st[1] + "</small></div>"; }).join("");
        var card = featured ? (function () {
          var out = featured.stock_status === "outofstock";
          var price = state.b2b ? ((featured.price != null) ? money(featured.price) : "—") : '<a class="login-price-link" href="' + U("account") + '">Login for price</a>';
          return '<div class="th-hero-card"><span class="th-hero-card__badge">' + s.badge + "</span>" +
            '<img src="' + esc(featured.image || s.img) + '" alt="' + esc(featured.name) + '" loading="' + (i === 0 ? "eager" : "lazy") + '" decoding="async">' +
            '<div class="th-hero-card__row"><div><div class="th-hero-card__name">' + esc(featured.name) + '</div><div class="th-hero-card__stars">★★★★★</div></div><div class="th-hero-card__price">' + price + "</div></div>" +
            '<button class="th-hero-card__add" type="button"' + (out ? " disabled" : "") + ' data-add="' + featured.id + '" data-slug="' + esc(featured.slug) + '" data-name="' + esc(featured.name) + '" data-img="' + esc(featured.image || s.img) + '" data-sku="' + esc(featured.sku) + '">' + (out ? "Out of stock" : "Add to cart") + "</button>" +
            "</div>";
        })() : '<div class="th-hero-card"><span class="th-hero-card__badge">' + s.badge + '</span><img src="' + esc(s.img) + '" alt="Featured wholesale part" loading="' + (i === 0 ? "eager" : "lazy") + '" decoding="async"></div>';
        return '<div class="th-slide ' + s.cls + (i === 0 ? " active" : "") + '" data-hero-slide="' + i + '">' +
          '<div class="th-slide__bg"></div>' +
          '<div class="container th-slide__grid">' +
            '<div class="th-slide__copy"><span class="th-eyebrow">' + s.eyebrow + "</span>" +
            "<h2>" + s.title[0] + " <span>" + s.title[1] + "</span></h2>" +
            "<p>" + s.sub + "</p>" +
            '<div class="th-slide__cta"><a class="btn ' + s.cta[0][2] + '" href="' + s.cta[0][1] + '">' + s.cta[0][0] + '</a><a class="btn ' + s.cta[1][2] + '" href="' + s.cta[1][1] + '">' + s.cta[1][0] + "</a></div>" +
            '<div class="th-slide__micro">' + micro + "</div>" +
            "</div>" +
            '<div class="th-slide__media">' + card + '<div class="th-hero-float">' + chips + "</div></div>" +
          "</div></div>";
      }).join("");
      return '<section class="th-hero-slider" data-hero>' +
        '<div class="th-hero-track">' + html + "</div>" +
        '<button class="th-hero-arrow th-hero-prev" type="button" data-hero-prev aria-label="Previous">' + icon("arrowLeft") + "</button>" +
        '<button class="th-hero-arrow th-hero-next" type="button" data-hero-next aria-label="Next">' + icon("arrow") + "</button>" +
        '<div class="th-hero-dots">' + dots + "</div>" +
        '<div class="th-hero-progress"><span data-hero-progress></span></div>' +
        "</section>";
    }

    function initHeroSlider(scope) {
      var root = scope.querySelector("[data-hero]"); if (!root) return;
      var slides = Array.prototype.slice.call(root.querySelectorAll("[data-hero-slide]"));
      var dots = Array.prototype.slice.call(root.querySelectorAll("[data-hero-dot]"));
      var bar = root.querySelector("[data-hero-progress]");
      var idx = 0, timer = null, DUR = 6000;
      function go(n) {
        idx = (n + slides.length) % slides.length;
        slides.forEach(function (s, i) { s.classList.toggle("active", i === idx); });
        dots.forEach(function (d, i) { d.classList.toggle("active", i === idx); });
        if (bar) { bar.style.transition = "none"; bar.style.width = "0"; void bar.offsetWidth; bar.style.transition = "width " + DUR + "ms linear"; bar.style.width = "100%"; }
      }
      function start() { stop(); timer = setInterval(function () { go(idx + 1); }, DUR); root.classList.add("playing"); if (bar) { bar.style.transition = "width " + DUR + "ms linear"; bar.style.width = "100%"; } }
      function stop() { if (timer) clearInterval(timer); timer = null; root.classList.remove("playing"); }
      var nx = root.querySelector("[data-hero-next]"), pv = root.querySelector("[data-hero-prev]");
      if (nx) nx.addEventListener("click", function () { go(idx + 1); start(); });
      if (pv) pv.addEventListener("click", function () { go(idx - 1); start(); });
      dots.forEach(function (d) { d.addEventListener("click", function () { go(parseInt(d.getAttribute("data-hero-dot"), 10)); start(); }); });
      root.addEventListener("mouseenter", stop);
      root.addEventListener("mouseleave", start);
      document.addEventListener("visibilitychange", function () { if (document.hidden) stop(); else start(); });
      bindAddButtons(root);
      start();
    }

    function revealOnScroll(root) {
      var els = root.querySelectorAll(".th-section");
      if (!("IntersectionObserver" in window)) return;
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); } });
      }, { threshold: .12, rootMargin: "0px 0px -40px 0px" });
      Array.prototype.forEach.call(els, function (el) { io.observe(el); });
    }

  function renderHomeShared(cardFn) {
    loading();
    var brandGroups = [
      { slug: "apple-parts", name: "Apple Parts" },
      { slug: "samsung-parts", name: "Samsung Parts" },
      { slug: "p-series", name: "Huawei Parts" },
      { slug: "xiaomi-parts", name: "Xiaomi Parts" },
      { slug: "google-pixel-parts", name: "Google Pixel Parts" },
      { slug: "devices", name: "Devices & Tools" }
    ];
    var preferred = ["apple-parts", "samsung-parts", "p-series", "xiaomi-parts", "google-pixel-parts", "devices", "accesoires", "cool-gadgets"];
    var IN_STOCK = function (p) { return p.stock_status !== "outofstock"; };
    getJSON(API + "/home").then(function (home) {
      var cats = home.categories || [], popular = (home.popular.items || []).filter(IN_STOCK), news = (home.newest.items || []).filter(IN_STOCK);
      var brandProds = brandGroups.map(function (b) { return ((home.brands[b.slug] || {}).items || []).filter(IN_STOCK); });
      var top = cats.filter(function (c) { return !c.parent_id; });
      var bySlug = {}; top.forEach(function (c) { bySlug[c.slug] = c; });
      var homeCats = preferred.filter(function (s) { return bySlug[s]; }).map(function (s) { return bySlug[s]; }).slice(0, 12);

      function slider(id, html) {
        return '<div class="slider" data-slider="' + id + '"><button class="slider-arrow slider-arrow--prev" type="button" data-slider-prev aria-label="Previous">‹</button><div class="slider-viewport"><div class="slider-track" id="' + id + '">' + html + '</div></div><button class="slider-arrow slider-arrow--next" type="button" data-slider-next aria-label="Next">›</button></div>';
      }
      function slides(items, max) {
        var its = max ? items.slice(0, max) : items;
        if (!its.length) return '<div class="slider-slide"><div class="empty">No products yet.</div></div>';
        return its.map(function (p) { return '<div class="slider-slide product-slide">' + cardFn(p) + "</div>"; }).join("");
      }
      var catSlides = homeCats.map(function (c) {
        var g = (c.name || "?").trim().charAt(0).toUpperCase();
        return '<div class="slider-slide cat-slide"><a class="th-cat" href="' + U("categories/" + c.slug) + '"><div class="th-cat__media"><span class="th-cat__glyph">' + esc(g) + '</span></div><span class="th-cat__name">' + esc(c.name) + "</span></a></div>";
      }).join("");
      var brandCards = brandGroups.slice(0, 4).map(function (b) {
        return '<a class="th-brand" href="' + U("categories/" + b.slug) + '"><span class="th-brand__logo">' + esc(b.name.split(" ")[0]) + '</span><strong>' + esc(b.name) + '</strong><small>Original &amp; compatible parts.</small><span>Shop now →</span></a>';
      }).join("");
      var bsGroups = brandGroups.map(function (b, i) { return { b: b, items: (brandProds[i] || []).slice(0, 8) }; }).filter(function (g) { return g.items.length > 0; });
      var bsTabs = bsGroups.map(function (g, i) { return '<button class="bs-tab' + (i === 0 ? " active" : "") + '" type="button" data-bs-tab="' + g.b.slug + '">' + esc(g.b.name) + "</button>"; }).join("");
      var bsPanels = bsGroups.map(function (g, i) {
        return '<div class="bs-panel' + (i === 0 ? " active" : "") + '" data-bs-panel="' + g.b.slug + '">' + slider("bs-" + g.b.slug, slides(g.items)) + "</div>";
      }).join("");
      var reviews = [
        { n: "TechRepair CH", t: "“Reliable parts and fast delivery across Switzerland. Our go-to B2B supplier.”" },
        { n: "Lux GSM", t: "“Wholesale prices are unbeatable and the catalog is huge.”" },
        { n: "iFix Lu", t: "“Quality tested components, minimal DOA. Highly recommended.”" },
        { n: "MobilePro", t: "“Great support and quick responses. High demand models always in stock.”" }
      ].map(function (r) {
        return '<div class="slider-slide"><div class="th-quote"><div class="th-quote__stars">★★★★★</div><p>' + r.t + '</p><div class="th-quote__by"><b>' + r.n + '</b><small>Verified customer</small></div></div></div>';
      }).join("");
      var brandStrip = ["Apple", "Samsung", "Huawei", "Xiaomi", "Google", "OPPO", "Sony", "Nokia"].map(function (n) { return "<span>" + n + "</span>"; }).join("");
      var featured = popular[0] || null;
      var heroImg = (featured && featured.image) ? featured.image : BASE + "assets/img/product-cable.webp";
      var newsImg = (news[0] && news[0].image) ? news[0].image : heroImg;
      var promoImg = BASE + "assets/img/b2b.webp";
      var offers = [
        { ic: "ticket", label: "Coupon code", title: "B2B10", desc: "Extra 10% on first wholesale order.", act: '<button type="button" class="th-offer__btn" data-copy="B2B10">Copy code</button>' },
        { ic: "gift", label: "Gift-ready", title: "Free wrapping", desc: "Available on eligible orders.", act: '<a class="th-offer__link" href="' + U("shop") + '">Learn more</a>' },
        { ic: "card", label: "Payment offer", title: "Net 30 terms", desc: "For approved business accounts.", act: '<a class="th-offer__link" href="' + U("account") + '">See details</a>' }
      ].map(function (o) { return '<article class="th-offer"><span class="th-offer__ic">' + icon(o.ic) + '</span><div><span>' + o.label + '</span><h3>' + o.title + '</h3><p>' + o.desc + '</p></div>' + o.act + "</article>"; }).join("");
      var benefits = [
        { ic: "bolt", t: "Flash Deals", s: "New offers daily" },
        { ic: "ticket", t: "Coupon Codes", s: "Extra savings" },
        { ic: "gift", t: "Gift Cards", s: "Sent by email" },
        { ic: "box", t: "Bundle Offers", s: "More items, lower price" },
        { ic: "star", t: "Loyalty Rewards", s: "Earn on every order" }
      ].map(function (b) { return '<article class="th-benefit"><span class="th-benefit__ic">' + icon(b.ic) + '</span><div><b>' + b.t + '</b><small>' + b.s + '</small></div></article>'; }).join("");
      var trust = [
        { ic: "truck", t: "Fast Shipping", s: "Switzerland & Luxembourg" },
        { ic: "tag", t: "B2B Pricing", s: "Net wholesale rates" },
        { ic: "shield", t: "Quality Parts", s: "Original & tested" },
        { ic: "headset", t: "Expert Support", s: "Mon–Fri, 9–18h" }
      ].map(function (x) { return '<div class="th-trust__item"><span class="th-trust__ic">' + icon(x.ic) + '</span><div><b>' + x.t + "</b><small>" + x.s + "</small></div></div>"; }).join("");

      var heroChips = top.slice(0, 4).map(function (c) {
        return '<a href="' + U("categories/" + c.slug) + '">' + esc(c.name) + '</a>';
      }).join("");
      var inStockCount = popular.filter(IN_STOCK).length;
      var commerceVisual = '<div class="fr-commerce" aria-hidden="true"><div class="fr-liquid"></div>' +
        '<i class="fr-shard fr-shard--1"></i><i class="fr-shard fr-shard--2"></i><i class="fr-shard fr-shard--3"></i><i class="fr-shard fr-shard--4"></i>' +
        '<span class="fr-bubble fr-bubble--1"></span><span class="fr-bubble fr-bubble--2"></span><span class="fr-bubble fr-bubble--3"></span>' +
        '<div class="fr-float fr-float--screen" title="Replacement display"><svg viewBox="0 0 24 24"><rect x="6.5" y="2" width="11" height="20" rx="2"/><path class="fr-crack" d="m13 3-2 5 3 2-4 4 2 2-2 5"/><path d="M10 19h4"/></svg></div>' +
        '<div class="fr-float fr-float--battery" title="Battery"><svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="16" rx="2"/><path d="M9 2h6v3M13 8l-3 5h4l-3 5"/></svg></div>' +
        '<div class="fr-float fr-float--camera" title="Camera module"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="3"/><circle cx="12" cy="12.5" r="4"/><circle cx="12" cy="12.5" r="1.4"/><path d="m8 5 1-2h6l1 2"/></svg></div>' +
        '<div class="fr-float fr-float--port" title="Charging port"><svg viewBox="0 0 24 24"><path d="M4 8h16v8H4zM8 11h8M7 19h10M9 16v3m6-3v3"/></svg></div>' +
        '<div class="fr-float fr-float--flex" title="Flex cable"><svg viewBox="0 0 24 24"><path d="M6 3h8v5H9v4h7v9H8v-5h3v-4H4V3z"/><path d="M8 5h4M10 19h4"/></svg></div>' +
        '<div class="fr-float fr-float--brand"><strong>Ferry Telecom</strong><small>The Best Repair Shop<br>in Switzerland</small></div>' +
        '<div class="fr-float fr-float--housing" title="Phone housing"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2.5"/><circle cx="10" cy="6" r="1.7"/><circle cx="14.5" cy="6" r="1.7"/><circle cx="10" cy="10.5" r="1.7"/></svg></div>' +
        '<div class="fr-glass-rim"></div></div>';
      var hero = '<section class="fr-hero" aria-labelledby="fr-hero-title">' +
        '<div class="fr-hero-mesh" aria-hidden="true"></div><div class="fr-hero-stage"><div class="fr-hero-body">' +
        '<p class="fr-eyebrow">Smart search &middot; fast order</p>' +
        '<h1 id="fr-hero-title">The right part, <span>first time.</span></h1>' +
        '<p class="fr-lead">Find your model, choose the right variant and order in one step.</p>' +
        '<form class="fr-search" id="frHeroSearch" role="search"><label class="fr-sr-only" for="frHeroSearchInput">Search the catalogue</label>' +
        '<div class="fr-search-field"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7.5"/><path d="m21 21-4.3-4.3"/></svg>' +
        '<input type="search" id="frHeroSearchInput" name="s" placeholder="Describe what you need in your own words" autocomplete="off">' +
        '<button type="submit" class="fr-search-submit">Smart Search</button></div>' +
        '<p class="fr-search-hint"><strong>Write it your way.</strong> Search by product, model, SKU or category.</p></form>' +
        '<nav class="fr-chips" aria-label="Popular categories">' + heroChips + '</nav>' +
        '<dl class="fr-stats"><div><dt>Products</dt><dd>' + popular.length + '</dd></div><div><dt>In stock</dt><dd>' + inStockCount + '</dd></div><div><dt>Categories</dt><dd>' + cats.length + '</dd></div></dl>' +
        '</div>' + commerceVisual + '</div></section>';

      app.innerHTML =
        hero +
        '<section class="th-section th-section--tight"><div class="container"><div class="th-head"><div><h2>Top Categories</h2></div><a class="th-link" href="' + U("categories") + '">View all →</a></div>' + slider("catRail", catSlides) + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head"><div><h2>Popular Products</h2></div><a class="th-link" href="' + U("shop") + '">View all →</a></div>' + slider("popularRail", slides(popular, 10)) + '</div></section>' +

        '<section class="th-promo"><div class="container th-promo__box"><div class="th-promo__copy"><p class="eyebrow">Exclusive B2B offer</p><h2>Stock Up &amp; Save on Wholesale Lots</h2><p>Order in bulk and unlock special pricing across the entire catalog.</p><a class="btn btn-primary" href="' + U("shop") + '">Shop the collection →</a><div class="th-promo__micro"><div><i>' + icon("arrow") + '</i><span>Better prices for higher quantities</span></div><div><i>' + icon("box") + '</i><span>Priority shipping for business accounts</span></div><div><i>' + icon("check") + '</i><span>Dedicated support from our team</span></div></div></div><div class="th-promo__art"><img src="' + promoImg + '" width="560" height="310" alt="Wholesale lots" loading="lazy" decoding="async"></div></div></section>' +

        '<section class="th-section th-section--tight"><div class="container th-trust">' + trust + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head th-head--center"><div><h2>Today’s Wholesale Picks</h2><p>Hand-picked high-demand parts with the best margins.</p></div></div>' + slider("flashRail", slides(news, 10)) + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head"><div><h2>Shop by Brand</h2></div><a class="th-link" href="' + U("shop") + '">All brands →</a></div><div class="th-brand-grid">' + brandCards + '</div></div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head"><div><h2>New Arrivals</h2></div><a class="th-link" href="' + U("shop") + '">View all →</a></div>' + slider("newRail", slides(news, 8)) + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head"><div><h2>Best Sellers by Category</h2></div><a class="th-link" href="' + U("shop") + '">See all best sellers →</a></div>' + (bsGroups.length ? '<div class="bs-tabs">' + bsTabs + '</div><div class="bs-panels">' + bsPanels + '</div>' : '<p class="th-empty">No products available yet.</p>') + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container th-offers">' + offers + '</div></section>' +

        '<section class="th-benefits"><div class="container th-benefits__grid">' + benefits + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container"><div class="th-head th-head--center"><div><h2>What Our Customers Say</h2><p>Verified feedback from wholesale partners.</p></div></div>' + slider("reviewsRail", reviews) + '</div></section>' +

        '<section class="th-section th-section--tight"><div class="container th-brands"><strong>Trusted by businesses worldwide</strong>' + brandStrip + '</div></section>' +

        '<section class="th-news"><div class="container th-news__inner"><div class="th-news__copy"><span class="th-news__ic">' + icon("mail") + '</span><div><h2>Subscribe to Our Newsletter</h2><p>New arrivals, exclusive offers and B2B insights.</p></div></div><form id="newsletterForm" class="th-news__form" data-newsletter><input type="email" required autocomplete="email" placeholder="Enter your email address" aria-label="Email address"><button class="btn btn-primary" type="submit">Subscribe</button></form></div></section>' +

        footerHtml();

      ["catRail", "popularRail", "flashRail", "newRail", "reviewsRail"].forEach(function (id) {
        var t = document.getElementById(id);
        if (t) makeSlider(t);
      });
      bindBestSellers(app);
      bindAddAttr();
      var heroSearch = document.getElementById("frHeroSearch");
      if (heroSearch) heroSearch.addEventListener("submit", function (event) {
        event.preventDefault();
        var input = document.getElementById("frHeroSearchInput");
        var query = input ? input.value.trim() : "";
        pendingShopInit=null;
        pushUrl(searchPath(query));
      });
      if ("IntersectionObserver" in window) { document.body.classList.add("js-reveal"); revealOnScroll(app); }
      wireNewsletter();
    }).catch(function (e) { app.innerHTML = '<div class="loading">Failed to load: ' + esc(e.message) + "</div>"; });
  }

  /* ---------- generic product card (used by template-4 / template-5) ---------- */
  function homeCard(p, cls) {
    var out = (p.stock_status === "outofstock");
    var img = p.image || PLACEHOLDER;
    var price = state.b2b ? ((p.price != null) ? money(p.price) : "—") : '<a class="login-price-link" href="' + U("account") + '">Login for price</a>';
    return '<article class="' + cls + (out ? " is-out" : "") + '">' +
      '<button class="wc-wish" type="button" aria-label="Add to wishlist" data-wl="' + p.id + '" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '" data-img="' + esc(img) + '" data-sku="' + esc(p.sku) + '">♡</button>' +
      '<div class="' + cls + '__media"><a href="' + U("product/" + p.slug) + '"><img src="' + esc(img) + '" alt="' + esc(p.name) + '" loading="lazy" decoding="async"></a></div>' +
      '<h3 class="' + cls + '__name"><a href="' + U("product/" + p.slug) + '">' + esc(p.name) + '</a></h3>' +
      '<p class="' + cls + '__stock">' + (out ? "Out of stock" : "In stock") + '</p>' +
      '<p class="' + cls + '__price">' + price + '</p>' +
      '<button class="' + cls + '__add" type="button"' + (out ? " disabled" : "") + ' data-add="' + p.id + '" data-slug="' + esc(p.slug) + '" data-name="' + esc(p.name) + '" data-img="' + esc(img) + '" data-sku="' + esc(p.sku) + '">' + (out ? "Out of stock" : "Add to cart") + '</button>' +
      '</article>';
  }

  /* ---------- fetch common home data (reused by template-4 / template-5) ---------- */
  function fetchHomeData(cb) {
    var brandGroups = [
      { slug: "apple-parts", name: "Apple Parts" },
      { slug: "samsung-parts", name: "Samsung Parts" },
      { slug: "p-series", name: "Huawei Parts" },
      { slug: "xiaomi-parts", name: "Xiaomi Parts" },
      { slug: "google-pixel-parts", name: "Google Pixel Parts" },
      { slug: "devices", name: "Devices & Tools" }
    ];
    var preferred = ["apple-parts", "samsung-parts", "p-series", "xiaomi-parts", "google-pixel-parts", "devices", "accesoires", "cool-gadgets"];
    var IN_STOCK = function (p) { return p.stock_status !== "outofstock"; };
    var calls = [
      getJSON(API + "/categories"),
      getJSON(API + "/products?page=1&stock[]=instock"),
      getJSON(API + "/products?sort=newest&page=1&stock[]=instock")
    ];
    brandGroups.forEach(function (b) { calls.push(getJSON(API + "/products?category=" + enc(b.slug) + "&page=1&stock[]=instock")); });
    Promise.all(calls).then(function (res) {
      var cats = res[0], popular = res[1].items.filter(IN_STOCK), news = res[2].items.filter(IN_STOCK);
      var brandProds = res.slice(3).map(function (r) { return (r.items || []).filter(IN_STOCK); });
      var top = cats.filter(function (c) { return !c.parent_id; });
      var bySlug = {}; top.forEach(function (c) { bySlug[c.slug] = c; });
      var homeCats = preferred.filter(function (s) { return bySlug[s]; }).map(function (s) { return bySlug[s]; }).slice(0, 12);
      var bsGroups = brandGroups.map(function (b, i) { return { b: b, items: (brandProds[i] || []).slice(0, 8) }; }).filter(function (g) { return g.items.length > 0; });
      cb({ cats: cats, popular: popular, news: news, top: top, homeCats: homeCats, bsGroups: bsGroups });
    }).catch(function (e) { app.innerHTML = '<div class="loading">Failed to load: ' + esc(e.message) + "</div>"; });
  }

  function homeSlider(id, html) {
    return '<div class="slider" data-slider="' + id + '"><button class="slider-arrow slider-arrow--prev" type="button" data-slider-prev aria-label="Previous">‹</button><div class="slider-viewport"><div class="slider-track" id="' + id + '">' + html + '</div></div><button class="slider-arrow slider-arrow--next" type="button" data-slider-next aria-label="Next">›</button></div>';
  }
  function homeSlides(items, max, cls) {
    var its = max ? items.slice(0, max) : items;
    if (!its.length) return '<div class="slider-slide"><div class="empty">No products yet.</div></div>';
    return its.map(function (p) { return '<div class="slider-slide">' + homeCard(p, cls) + "</div>"; }).join("");
  }
  function homeBsTabs(bsGroups, cls) {
    var bsTabs = bsGroups.map(function (g, i) { return '<button class="bs-tab' + (i === 0 ? " active" : "") + '" data-bs-tab="' + g.b.slug + '">' + esc(g.b.name) + '</button>'; }).join("");
    var bsPanels = bsGroups.map(function (g, i) { return '<div class="bs-panel' + (i === 0 ? " active" : "") + '" data-bs-panel="' + g.b.slug + '">' + homeSlider("tbs-" + g.b.slug, homeSlides(g.items, 12, cls)) + '</div>'; }).join("");
    return { bsTabs: bsTabs, bsPanels: bsPanels };
  }
  function homeReviews() {
    var reviews = [
      { n: "TechRepair CH", t: "“Reliable parts and fast delivery across Switzerland. Our go-to B2B supplier.”" },
      { n: "Lux GSM", t: "“Wholesale prices are unbeatable and the catalog is huge.”" },
      { n: "iFix Lu", t: "“Quality tested components, minimal DOA. Highly recommended.”" },
      { n: "MobilePro", t: "“Great support and quick responses. High demand models always in stock.”" },
      { n: "GSM Center", t: "“Fast shipping and genuine stock. Perfect for our repair shops.”" },
      { n: "PhoneClinic", t: "“Bulk pricing helps our margin a lot. Easy reordering.”" }
    ];
    return reviews.map(function (r) {
      return '<div class="slider-slide"><div class="t-quote"><div class="t-quote__stars">★★★★★</div><p>' + r.t + '</p><div class="t-quote__by"><b>' + r.n + '</b><small>Verified customer</small></div></div></div>';
    }).join("");
  }

  function renderHomeT4() {
    loading();
    fetchHomeData(function (d) {
      function slider(id, html) { return homeSlider(id, html); }
      function slides(items, max) { return homeSlides(items, max, "t4-product"); }
      var catTiles = d.homeCats.slice(0, 8).map(function (c) {
        var g = (c.name || "?").trim().charAt(0).toUpperCase();
        return '<a class="t4-cat" href="' + U("categories/" + c.slug) + '"><span class="t4-cat__glyph">' + esc(g) + '</span><span class="t4-cat__name">' + esc(c.name) + '</span></a>';
      }).join("");
      var bs = homeBsTabs(d.bsGroups, "t4-product");
      var reviews = homeReviews();
      app.innerHTML =
        '<section class="t4-hero"><div class="container t4-hero__inner">' +
          '<p class="t4-eyebrow">B2B Wholesale · Switzerland &amp; Luxembourg</p>' +
          '<h1 class="t4-title">Wholesale Mobile Phone Parts &amp; Repair Equipment</h1>' +
          '<p class="t4-sub">Original and tested parts for Apple, Samsung, Huawei, Xiaomi and more. Net wholesale prices for registered business accounts.</p>' +
          '<div class="t4-actions"><a class="btn btn-primary" href="' + U("shop") + '">Browse Catalog</a><a class="btn t4-ghost" href="' + U("account") + '">Open B2B Account</a></div>' +
        '</div></section>' +

        '<section class="t4-sec"><div class="container"><div class="t4-head"><div><h2>Shop by Category</h2></div><a href="' + U("categories") + '">All categories →</a></div><div class="t4-cats">' + catTiles + '</div></div></section>' +

        '<section class="t4-sec"><div class="container"><div class="t4-head"><div><h2>Popular Products</h2></div><a href="' + U("shop") + '">View all →</a></div>' + homeSlider("t4Popular", slides(d.popular, 12)) + '</div></section>' +

        '<section class="t4-sec t4-deal"><div class="container t4-deal__box">' +
          '<div class="t4-deal__copy"><p class="t4-eyebrow">Exclusive B2B offer</p><h2>Stock Up &amp; Save on Wholesale Lots</h2><p>Order in bulk and unlock special pricing across the entire catalog.</p><a class="btn btn-primary" href="' + U("shop") + '">Shop the collection →</a></div>' +
          '<div class="t4-deal__big">40%<small>OFF</small></div>' +
        '</div></section>' +

        '<section class="t4-sec"><div class="container"><div class="t4-head"><div><h2>New Arrivals</h2></div><a href="' + U("shop") + '">View all →</a></div>' + homeSlider("t4New", slides(d.news, 10)) + '</div></section>' +

        '<section class="t4-sec"><div class="container"><div class="t4-head"><div><h2>Best Sellers by Category</h2></div><a href="' + U("shop") + '">See all →</a></div>' + (d.bsGroups.length ? '<div class="bs-tabs">' + bs.bsTabs + '</div><div class="bs-panels">' + bs.bsPanels + '</div>' : '<p class="empty">No products yet.</p>') + '</div></section>' +

        '<section class="t4-sec"><div class="container"><div class="t4-head t4-head--center"><div><h2>What Our Customers Say</h2></div></div>' + homeSlider("t4Reviews", reviews) + '</div></section>' +

        '<section class="t4-news"><div class="container t4-news__inner"><div class="t4-news__copy"><span class="t4-news__ic">✉</span><div><h2>Subscribe to Our Newsletter</h2><p>New arrivals, exclusive offers and B2B insights.</p></div></div><form id="newsletterForm" class="t4-news__form" data-newsletter><input type="email" required autocomplete="email" placeholder="Enter your email address" aria-label="Email address"><button class="btn btn-primary" type="submit">Subscribe</button></form></div></section>' +

        footerHtml();

      ["t4Popular", "t4New", "t4Reviews"].concat(d.bsGroups.map(function (g) { return "tbs-" + g.b.slug; })).forEach(function (id) {
        var t = document.getElementById(id); if (t) makeSlider(t);
      });
      bindBestSellers(app); bindAddAttr(); wireNewsletter();
    });
  }

  function renderHomeT5() {
    loading();
    fetchHomeData(function (d) {
      function slides(items, max) { return homeSlides(items, max, "t5-product"); }
      var railCats = d.top.slice(0, 6).map(function (c) {
        return '<a class="t5-rail__item" href="' + U("categories/" + c.slug) + '"><span class="t5-rail__dot"></span>' + esc(c.name) + '</a>';
      }).join("");
      var heroImg = (d.popular[0] && d.popular[0].image) ? d.popular[0].image : BASE + "assets/img/product-cable.webp";
      var brandStrip = ["Apple", "Samsung", "Huawei", "Xiaomi", "Google", "OPPO", "Sony", "Nokia"].map(function (n) { return "<span>" + n + "</span>"; }).join("");
      var bs = homeBsTabs(d.bsGroups, "t5-product");
      var reviews = homeReviews();
      app.innerHTML =
        '<section class="t5-hero"><div class="container t5-hero__grid">' +
          '<aside class="t5-rail"><p class="t5-rail__title">Categories</p>' + railCats + '</aside>' +
          '<div class="t5-hero__main">' +
            '<p class="t5-eyebrow">⚡ Up to 40% OFF · Wholesale</p>' +
            '<h1 class="t5-title">Wholesale Mobile Phone Parts &amp; Repair Equipment</h1>' +
            '<p class="t5-sub">Original and tested parts for Apple, Samsung, Huawei, Xiaomi and more. Net wholesale prices for registered business accounts.</p>' +
            '<div class="t5-actions"><a class="btn btn-primary" href="' + U("shop") + '">Browse Catalog</a><a class="btn t5-ghost" href="' + U("account") + '">Open B2B Account</a></div>' +
            '<div class="t5-stats"><div><strong>10,000+</strong><small>Products</small></div><div><strong>6+</strong><small>Brands</small></div><div><strong>CH &amp; LU</strong><small>Fast Delivery</small></div></div>' +
          '</div>' +
          '<div class="t5-hero__media"><img src="' + esc(heroImg) + '" width="540" height="420" alt="Featured wholesale parts" fetchpriority="high" decoding="async"></div>' +
        '</div></section>' +

        '<section class="t5-dealstrip"><div class="container t5-dealstrip__inner">' +
          '<div><b>40% OFF</b><span>Wholesale lots</span></div>' +
          '<div><b>Free shipping</b><span>Orders over CHF 500</span></div>' +
          '<div><b>Net 30</b><span>For business accounts</span></div>' +
          '<a class="btn btn-primary" href="' + U("shop") + '">Shop now →</a>' +
        '</div></section>' +

        '<section class="t5-sec"><div class="container"><div class="t5-head"><div><h2>Popular Products</h2></div><a href="' + U("shop") + '">View all →</a></div>' + homeSlider("t5Popular", slides(d.popular, 12)) + '</div></section>' +

        '<section class="t5-sec"><div class="container"><div class="t5-head"><div><h2>Shop by Brand</h2></div><a href="' + U("shop") + '">All brands →</a></div><div class="t5-brands">' + brandStrip + '</div></div></section>' +

        '<section class="t5-sec"><div class="container"><div class="t5-head"><div><h2>New Arrivals</h2></div><a href="' + U("shop") + '">View all →</a></div>' + homeSlider("t5New", slides(d.news, 10)) + '</div></section>' +

        '<section class="t5-sec"><div class="container"><div class="t5-head"><div><h2>Best Sellers by Category</h2></div><a href="' + U("shop") + '">See all →</a></div>' + (d.bsGroups.length ? '<div class="bs-tabs">' + bs.bsTabs + '</div><div class="bs-panels">' + bs.bsPanels + '</div>' : '<p class="empty">No products yet.</p>') + '</div></section>' +

        '<section class="t5-sec"><div class="container"><div class="t5-head t5-head--center"><div><h2>What Our Customers Say</h2></div></div>' + homeSlider("t5Reviews", reviews) + '</div></section>' +

        '<section class="t5-news"><div class="container t5-news__inner"><div class="t5-news__copy"><span class="t5-news__ic">✉</span><div><h2>Subscribe to Our Newsletter</h2><p>New arrivals, exclusive offers and B2B insights.</p></div></div><form id="newsletterForm" class="t5-news__form" data-newsletter><input type="email" required autocomplete="email" placeholder="Enter your email address" aria-label="Email address"><button class="btn btn-primary" type="submit">Subscribe</button></form></div></section>' +

        footerHtml();

      ["t5Popular", "t5New", "t5Reviews"].concat(d.bsGroups.map(function (g) { return "tbs-" + g.b.slug; })).forEach(function (id) {
        var t = document.getElementById(id); if (t) makeSlider(t);
      });
      bindBestSellers(app); bindAddAttr(); wireNewsletter();
    });
  }

  function renderHome() {
    loading();
    var brandGroups = [
      { slug: "apple-parts", name: "Apple Parts", cls: "camp--apple" },
      { slug: "samsung-parts", name: "Samsung Parts", cls: "camp--samsung" },
      { slug: "p-series", name: "Huawei Parts", cls: "camp--huawei" },
      { slug: "xiaomi-parts", name: "Xiaomi Parts", cls: "camp--xiaomi" },
      { slug: "google-pixel-parts", name: "Google Pixel Parts", cls: "camp--google" }
    ];
    var preferred = ["apple-parts", "samsung-parts", "p-series", "xiaomi-parts", "google-pixel-parts", "devices", "accesoires", "cool-gadgets", "it-multimedia", "lamps-lighting"];
    var IN_STOCK = function (p) { return p.stock_status !== "outofstock"; };
    var calls = [
      getJSON(API + "/categories"),
      getJSON(API + "/products?page=1&stock[]=instock"),
      getJSON(API + "/products?sort=newest&page=1&stock[]=instock")
    ];
    brandGroups.forEach(function (b) { calls.push(getJSON(API + "/products?category=" + enc(b.slug) + "&page=1&stock[]=instock")); });

    Promise.all(calls).then(function (res) {
      var cats = res[0], popular = res[1].items.filter(IN_STOCK), news = res[2].items.filter(IN_STOCK);
      var brandProds = res.slice(3).map(function (r) { return { items: (r.items || []).filter(IN_STOCK) }; });
      var top = cats.filter(function (c) { return !c.parent_id; });
      var bySlug = {}; top.forEach(function (c) { bySlug[c.slug] = c; });
      var homeCats = preferred.filter(function (s) { return bySlug[s]; }).map(function (s) { return bySlug[s]; }).slice(0, 12);

      function sliderHtml(id, items) {
        var inner = items.length ? items.map(function (p) { return '<div class="slider-slide">' + productCard(p) + "</div>"; }).join("") : '<div class="slider-slide"><div class="empty">No products yet.</div></div>';
        return '<div class="slider" data-slider="' + id + '">' +
          '<button class="slider-arrow slider-arrow--prev" type="button" data-slider-prev aria-label="Previous">‹</button>' +
          '<div class="slider-viewport"><div class="slider-track" id="' + id + '">' + inner + "</div></div>" +
          '<button class="slider-arrow slider-arrow--next" type="button" data-slider-next aria-label="Next">›</button></div>';
      }
      function catSliderHtml(cats) {
        var inner = cats.map(function (c) { return '<div class="slider-slide cat-slide">' + c + "</div>"; }).join("");
        return '<div class="slider" data-slider="catRail">' +
          '<button class="slider-arrow slider-arrow--prev" type="button" data-slider-prev aria-label="Previous">‹</button>' +
          '<div class="slider-viewport"><div class="slider-track" id="catRail">' + inner + "</div></div>" +
          '<button class="slider-arrow slider-arrow--next" type="button" data-slider-next aria-label="Next">›</button></div>';
      }

      var heroMedia = (popular[0] ? '<div class="hp-hero__feature"><img src="' + esc(popular[0].image || PLACEHOLDER) + '" alt=""></div>' : "") +
        (popular[1] ? '<div class="hp-hero__float hp-hero__float--1"><img src="' + esc(popular[1].image || PLACEHOLDER) + '" alt=""></div>' : "") +
        (popular[2] ? '<div class="hp-hero__float hp-hero__float--2"><img src="' + esc(popular[2].image || PLACEHOLDER) + '" alt=""></div>' : "");

      var catItems = homeCats.map(function (c) {
        return '<a class="cat-card" ' + 'href="' + U("categories/" + c.slug) + '">' +
          '<span class="cat-card__media">' + catMedia(c) + '</span>' +
          '<span class="cat-card__name">' + esc(c.name) + '</span>' + '</a>';
      });

      var brandCards = brandGroups.slice(0, 4).map(function (b) {
        return '<a class="campaign-card ' + b.cls + '" href="' + U("categories/" + b.slug) + '"><div><span>Wholesale</span><h3>' + esc(b.name) + '</h3><p>Original &amp; compatible parts.</p><b>Shop now →</b></div></a>';
      }).join("");

      var bestGroups = brandGroups.slice(0, 4).map(function (b, i) {
        return { b: b, items: (brandProds[i] && brandProds[i].items) ? brandProds[i].items.slice(0, 8) : [] };
      }).filter(function (g) { return g.items.length > 0; });
      var bestTabs = bestGroups.map(function (g, i) {
        return '<button type="button" class="best-tab' + (i === 0 ? " is-active" : "") + '" data-best-tab="' + i + '">' + esc(g.b.name) + "</button>";
      }).join("");
      var bestPanels = bestGroups.map(function (g, i) {
        return '<div class="best-panel' + (i === 0 ? " is-active" : "") + '" data-best-panel="' + i + '">' + sliderHtml("bestRail" + i, g.items) + "</div>";
      }).join("");
      var bestBody = bestGroups.length ? ('<div class="best-tabs">' + bestTabs + "</div>" + bestPanels) : '<div class="empty">No products available yet.</div>';

      var brandStrip = ["Apple", "Samsung", "Huawei", "Xiaomi", "Google", "OnePlus", "OPPO", "Sony", "Nokia"].map(function (n) { return "<span>" + n + "</span>"; }).join("");

      var reviewData = [
        { n: "TechRepair CH", t: "“Reliable parts and fast delivery across Switzerland. Our go-to B2B supplier.”", s: "Verified customer" },
        { n: "Lux GSM", t: "“Wholesale prices are unbeatable and the catalog is huge.”", s: "Verified customer" },
        { n: "iFix Lu", t: "“Quality tested components, minimal DOA. Highly recommended.”", s: "Verified customer" },
        { n: "MobilePro", t: "“Great support and quick restocking on high-demand models.”", s: "Verified customer" }
      ];
      var reviewCards = reviewData.map(function (r) {
        return '<div class="review-card"><div class="review-stars">★★★★★</div><p>' + r.t + '</p><div class="review-by"><b>' + r.n + '</b><small>' + r.s + '</small></div></div>';
      });
      var reviews = reviewCards.join("");
      var reviewSlides = reviewCards.map(function (c) { return '<div class="slider-slide review-slide">' + c + "</div>"; }).join("");

      app.innerHTML =
        '<section class="hp-hero"><div class="container hp-hero__inner">' +
          '<div class="hp-hero__copy"><span class="eyebrow">⚡ Trusted B2B Parts Platform</span>' +
          "<h1>Wholesale Mobile Phone Parts &amp; <span class=\"accent\">Repair Equipment</span></h1>" +
          "<p>Original &amp; tested-compatible parts for Apple, Samsung, Huawei, Xiaomi and more. Net wholesale prices for registered business accounts.</p>" +
          '<div class="hp-hero__actions"><a class="btn btn-primary" href="' + U("shop") + '">Browse Catalog</a><a class="btn" href="' + U("account") + '">Open B2B Account</a></div>' +
          '<div class="hp-hero__stats"><div><b>10,000+</b><span>Products</span></div><div><b>6+</b><span>Brands</span></div><div><b>CH &amp; LU</b><span>Fast delivery</span></div></div>' +
          "</div>" +
          '<div class="hp-hero__media"><div class="hp-hero__badge">UP TO<br><strong>40%</strong><br>OFF</div>' + heroMedia + "</div>" +
        "</div></section>" +

        '<section class="section container"><div class="section-head"><h2>Top Categories</h2><a href="' + U("categories") + '">View all →</a></div>' +
          catSliderHtml(catItems) + "</section>" +

        '<section class="section container"><div class="section-head"><h2>Popular Products</h2><a href="' + U("shop") + '">View all →</a></div>' +
          sliderHtml("popularRail", popular) + "</section>" +

        '<section class="hp-deal container"><div class="hp-deal__copy"><span class="eyebrow">Exclusive B2B offer</span>' +
          "<h2>Stock Up &amp; Save on Wholesale Lots</h2>" +
          "<p>Order in bulk and unlock net wholesale pricing across the entire catalog.</p>" +
          '<a class="btn btn-primary" href="' + U("shop") + '">Shop the collection →</a>' +
          '<div class="hp-deal__count" id="homeCountdown"></div></div>' +
          '<div class="hp-deal__media"><img src="' + esc(((popular[3] || popular[0]) || {}).image || PLACEHOLDER) + '" alt="Wholesale lot"></div></section>' +

        '<section class="hp-trust container"><article><span class="hp-trust__ic">🚚</span><div><b>Fast Shipping</b><small>Switzerland &amp; Luxembourg</small></div></article>' +
          '<article><span class="hp-trust__ic">🏷️</span><div><b>B2B Pricing</b><small>Net wholesale rates</small></div></article>' +
          '<article><span class="hp-trust__ic">🛠️</span><div><b>Quality Parts</b><small>Original &amp; tested</small></div></article>' +
          '<article><span class="hp-trust__ic">🎧</span><div><b>Expert Support</b><small>Mon–Fri, 9–18h</small></div></article></section>' +

        '<section class="section container hp-flash"><div class="hp-flash__intro"><span class="hp-flash__ic">⚡</span><span>Limited time</span>' +
          "<h2>Today’s Wholesale Picks</h2><p>Hand-picked high-demand parts with the best margins.</p>" +
          '<div class="hp-flash__count" id="flashCountdown"></div></div>' +
          sliderHtml("flashRail", news) + "</section>" +

        '<section class="section container"><div class="section-head"><h2>Shop by Brand</h2><a href="' + U("shop") + '">All brands →</a></div>' +
          '<div class="campaign-grid">' + brandCards + "</div></section>" +

        '<section class="section container"><div class="section-head"><h2>New Arrivals</h2><a href="' + U("shop") + '">View all →</a></div>' +
          sliderHtml("newRail", news) + "</section>" +

        '<section class="section container"><div class="section-head"><h2>Best Sellers by Category</h2><a href="' + U("shop") + '">See rankings →</a></div>' +
          bestBody + "</section>" +

        '<section class="hp-offers container">' +
          '<article class="offer-card"><span class="offer-card__ic">🎟️</span><div><span>Coupon code</span><h3>B2B10</h3><p>Extra 10% on first wholesale order.</p></div><button type="button" data-copy="B2B10">Copy code</button></article>' +
          '<article class="offer-card"><span class="offer-card__ic">🎁</span><div><span>Gift-ready</span><h3>Free wrapping</h3><p>Available on eligible orders.</p></div><a href="' + U("shop") + '">Learn more</a></article>' +
          '<article class="offer-card"><span class="offer-card__ic">💳</span><div><span>Payment offer</span><h3>Net 30 terms</h3><p>For approved business accounts.</p></div><a href="' + U("account") + '">See details</a></article>' +
        "</section>" +

        '<section class="hp-benefits"><div class="container hp-benefits__grid">' +
          '<article><span>⚡</span><div><b>Flash Deals</b><small>New offers daily</small></div></article>' +
          '<article><span>🎟️</span><div><b>Coupon Codes</b><small>Extra savings</small></div></article>' +
          '<article><span>🎁</span><div><b>Gift Cards</b><small>Sent by email</small></div></article>' +
          '<article><span>📦</span><div><b>Bundle Offers</b><small>More items, lower price</small></div></article>' +
          '<article><span>⭐</span><div><b>Loyalty Rewards</b><small>Earn on every order</small></div></article>' +
        "</div></section>" +

        '<section class="section container hp-reviews"><div class="section-head section-head--center"><div><h2>What Our Customers Say</h2><p>Verified feedback from wholesale partners.</p></div></div>' +
          '<div class="slider" data-slider="reviewRail">' +
            '<button class="slider-arrow slider-arrow--prev" type="button" data-slider-prev aria-label="Previous">‹</button>' +
            '<div class="slider-viewport"><div class="slider-track" id="reviewRail">' + reviewSlides + "</div></div>" +
            '<button class="slider-arrow slider-arrow--next" type="button" data-slider-next aria-label="Next">›</button></div></section>' +

        '<section class="hp-brands container"><span class="hp-brands__label">Trusted brands:</span>' + brandStrip + "</section>" +

        '<section class="hp-news"><div class="container hp-news__inner">' +
          '<div class="hp-news__copy"><span class="hp-news__ic">✉️</span><div><h2>Subscribe to Our Newsletter</h2><p>New arrivals, exclusive offers and B2B coupon alerts.</p></div></div>' +
          '<form id="newsletterForm" class="hp-news__form"><input type="email" required placeholder="Enter your email address"><button type="submit">Subscribe</button></form>' +
        "</div></section>" + footerHtml();

      bindAddButtons();
      makeSlider(document.getElementById("catRail"));
      makeSlider(document.getElementById("popularRail"));
      makeSlider(document.getElementById("flashRail"));
      makeSlider(document.getElementById("newRail"));
      makeSlider(document.getElementById("reviewRail"));

      var bestInited = {};
      bestGroups.forEach(function (g, i) {
        if (i === 0) { makeSlider(document.getElementById("bestRail" + i)); bestInited[i] = true; }
      });
      Array.prototype.forEach.call(document.querySelectorAll(".best-tab"), function (tab) {
        tab.addEventListener("click", function () {
          var idx = tab.getAttribute("data-best-tab");
          Array.prototype.forEach.call(document.querySelectorAll(".best-tab"), function (t) { t.classList.toggle("is-active", t === tab); });
          Array.prototype.forEach.call(document.querySelectorAll(".best-panel"), function (p) { p.classList.toggle("is-active", p.getAttribute("data-best-panel") === idx); });
          var rail = document.getElementById("bestRail" + idx);
          if (rail && !bestInited[idx]) { makeSlider(rail); bestInited[idx] = true; }
        });
      });

      startHomeCountdowns();
      wireNewsletter();
    }).catch(function (e) { app.innerHTML = '<div class="loading">Failed to load: ' + esc(e.message) + "</div>"; });
  }

  /* ---------------- SHOP (AJAX filters, URL never changes) ---------------- */
  function enterShop(initial) {
    initial = initial || {};
    if (pendingShopInit) { initial = Object.assign({}, initial, pendingShopInit); pendingShopInit = null; }
    shopState = { category: initial.category || "", q: initial.q || "", sort: initial.sort || "", attributes: (initial.attributes || []).slice(), stock: (initial.stock || []).slice(), page: initial.page || 1, view: initial.view || "list" };
    loading();
    Promise.all([getJSON(API + "/categories"), getJSON(API + "/attributes"+(shopState.category?"?category="+enc(shopState.category):"")), getJSON(API + "/settings")]).then(function (r) {
      shopCats = r[0]; shopAttrs = r[1]; shopFilterStyle = (r[2] && r[2].shop_filter_style === "checkmarks") ? "checkmarks" : "images";
      shopProductsPerPage = 12;
      shopLoadingMode = "pagination";
      catById = {}; catSlugToId = {};
      shopCats.forEach(function (c) { catById[c.id] = c; catSlugToId[c.slug] = c.id; });
      renderShopShell();
      loadShop();
    }).catch(function (e) { app.innerHTML = '<div class="loading">Failed to load: ' + esc(e.message) + "</div>"; });
  }

  function renderShopShell() {
    if (THEME === "template-6" && window.FerryMain) {
      app.innerHTML = FerryMain.shopHTML(shopState, shopCats, shopAttrs, state.b2b);
      bindShopEvents();
      FerryMain.bindShop(shopState, function (changes, rebuild) {
        Object.assign(shopState, changes);
        if (rebuild) renderShopShell();
        loadShop();
      });
      return;
    }
    var catHtml = '<div class="widget filter-cat-w filter-style-' + shopFilterStyle + '"><h3 class="widget-title">Category Filter</h3>' +
      '<ul class="cat-filter-list">' +
      buildCategoryFilter(shopCats, shopState.category) +
      '</ul></div>';

    var stockHtml = buildStockFilter();

    var attrHtml = '';
    shopAttrs.filter(function (a) {
      var slug = String(a.slug || "").toLowerCase();
      return slug !== "ean" && slug !== "hscode";
    }).forEach(function (a) {
      attrHtml += '<div class="widget attribute-widget" data-attribute="' + esc(a.slug) + '"><h3 class="widget-title">' + esc(a.name) + '</h3><div class="attr-block"><ul class="attr-list">';
      a.values.forEach(function (v) {
        var key = a.slug + ":" + v.slug;
        var color = String(a.slug || "").toLowerCase() === "color" ? '<i class="filter-color-swatch" style="--filter-color:' + esc(colorSwatch(v.slug)) + '"></i>' : '';
        attrHtml += '<li><label class="attr-option' + (color ? ' attr-option--color' : '') + '"><input type="checkbox" class="attr-chk" value="' + esc(key) + '">' + color + '<span>' + esc(v.value) + "</span></label></li>";
      });
      attrHtml += "</ul></div></div>";
    });

    app.innerHTML =
      '<div class="container"><div class="breadcrumb"><a href="' + U("") + '">Home</a> / <a href="' + U("shop") + '">Shop</a>' +
      (shopState.category ? ' / <span id="crumb-cat"></span>' : "") + "</div></div>" +
      '<div class="shop-layout container ferry-main-catalog">' +
      '<aside class="sidebar" id="shop-sidebar">' +
      '<div class="sidebar-body" id="sidebar-body"><div class="sidebar-inner">' + catHtml + stockHtml + attrHtml + "</div></div>" +
      "</aside>" +
      '<div class="shop-main" id="shop-main">' +
      buildCategoryCarousel() +
      '<div class="toolbar"><span class="result-count" id="result-count"></span>' +
      '<div class="toolbar-right">' +
      '<div class="view-toggle">' +
      '<button type="button" class="view-btn' + (shopState.view === "list" ? " active" : "") + '" data-view="list" id="view-list" aria-label="List view">&#9776; List</button>' +
      '<button type="button" class="view-btn' + (shopState.view === "grid" ? " active" : "") + '" data-view="grid" id="view-grid" aria-label="Grid view">&#9783; Grid</button>' +
      '</div>' +
      '<select class="sort-select" id="sort-select">' +
      '<option value="">Sort: Default</option><option value="newest">Newest</option>' +
      '<option value="name">Name A–Z</option><option value="price">Price low–high</option></select>' +
      '</div></div>' +
      '<div class="products" id="products-grid"></div>' +
      '<div class="pagination" id="shop-pagination"></div>' +
      "</div></div>";

    bindShopEvents();

    if (shopState.category && catById[catSlugToId[shopState.category]]) {
      document.getElementById("crumb-cat").textContent = catById[catSlugToId[shopState.category]].name;
    }
  }

  function childrenOfCat(slug) {
    var id = catSlugToId[slug];
    if (!id) return [];
    return shopCats.filter(function (c) { return c.parent_id === id; });
  }

  function buildCategoryCarousel() {
    if (!shopState.category || !catSlugToId[shopState.category]) return "";
    var kids = childrenOfCat(shopState.category);
    if (!kids.length) return "";
    var loop = kids.length > 5;
    function categoryItems(cloneSet) {
      return kids.map(function (c) {
        return '<div class="pop-cat" data-carousel-set="' + cloneSet + '"' + (cloneSet !== "original" ? ' aria-hidden="true"' : '') + '><a href="' + U("categories/" + c.slug) + '"' + (cloneSet !== "original" ? ' tabindex="-1"' : '') + '>' +
          '<span class="pop-cat__img">' + catMedia(c) + "</span>" +
          '<span class="pop-cat__name">' + esc(c.name) + "</span></a></div>";
      }).join("");
    }
    var items = loop ? categoryItems("before") + categoryItems("original") + categoryItems("after") : categoryItems("original");
    return '<section class="pop-cats container"><div class="pop-cats__head">' +
      '<h3 class="pop-cats__title">Popular Categories</h3>' +
      (loop ? '<div class="pop-cats__nav"><button type="button" class="pop-prev" aria-label="Previous categories">‹</button>' +
      '<button type="button" class="pop-next" aria-label="Next categories">›</button></div>' : '') + '</div>' +
      '<div class="pop-cats__track' + (loop ? ' is-loop' : ' is-static') + '" id="pop-cats-track" data-count="' + kids.length + '">' + items + "</div></section>";
  }

  function renderCategories() {
    loading();
    getJSON(API + "/categories").then(function (cats) {
      var byParent = {}, tops = [], allCats = {};
      cats.forEach(function (c) {
        allCats[c.id] = c;
        if (c.parent_id == null) tops.push(c);
        else (byParent[c.parent_id] = byParent[c.parent_id] || []).push(c);
      });
      function descendants(id, out) {
        (byParent[id] || []).forEach(function (c) { out.push(c); descendants(c.id, out); });
        return out;
      }
      var html = '<section class="section container"><div class="section-head"><h2>All Categories</h2><a href="' + U("shop") + '">Browse all products →</a></div>';
      tops.forEach(function (t) {
        var all = [t].concat(descendants(t.id, []));
        html += '<div class="cat-group"><div class="cat-group__title"><a href="' + U("categories/" + t.slug) + '">' + esc(t.name) + " (" + all.length + ")</a></div>";
        html += '<div class="cat-grid">';
        all.forEach(function (c) {
          html += '<a class="cat-tile" href="' + U("categories/" + c.slug) + '">' +
            '<span class="cat-tile__media">' + catMedia(c) + "</span>" +
            '<span class="cat-tile__name">' + esc(c.name) + "</span></a>";
        });
        html += "</div></div>";
      });
      html += "</section>";
      app.innerHTML = html;
      window.scrollTo(0, 0);
    }).catch(function (e) {
      app.innerHTML = '<div class="loading">Failed to load: ' + esc(e.message) + "</div>";
    });
  }

  function buildCategoryFilter(cats, activeSlug) {
    var childrenOf = {}, topList = [];
    cats.forEach(function (c) {
      if (c.parent_id) (childrenOf[c.parent_id] = childrenOf[c.parent_id] || []).push(c);
      else topList.push(c);
    });
    function item(val, label, back, category) {
      var checked = ((val && val === activeSlug) || (!val && !activeSlug)) ? " checked" : "";
      var media = category ? catMedia(category) : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';
      return '<li class="cat-filter-item' + (back ? " cat-filter-back" : "") + '"><label><input type="checkbox" class="cat-chk" value="' + esc(val) + '"' + checked + '><span class="cat-filter-thumb">' + media + '</span><span class="cat-name" title="' + esc(label) + '">' + esc(label) + "</span></label></li>";
    }
    var html = item("", "All Categories", false, null);
    var cur = (activeSlug && catSlugToId[activeSlug]) ? catById[catSlugToId[activeSlug]] : null;
    if (cur && cur.parent_id && catById[cur.parent_id]) {
      var par = catById[cur.parent_id];
      html += item(par.slug, "← Back to " + par.name, true, par);
    }
    var roots = cur ? (childrenOf[cur.id] || []) : topList;
    if (roots.length === 0 && cur) roots = [cur];
    html += roots.map(function (c) { return item(c.slug, c.name, false, c); }).join("");
    return html;
  }

  function buildStockFilter() {
    var opts = [
      { v: "instock", l: "In stock" },
      { v: "outofstock", l: "Out of stock" }
    ];
    return '<div class="widget"><h3 class="widget-title">Availability</h3><ul class="stock-filter-list">' +
      opts.map(function (o) {
        var checked = (shopState.stock.indexOf(o.v) >= 0) ? " checked" : "";
        return '<li class="stock-filter-item"><label><input type="checkbox" class="stock-chk" value="' + o.v + '"' + checked + '> ' + o.l + "</label></li>";
      }).join("") +
      "</ul></div>";
  }

  function bindShopEvents() {
    var sb = document.getElementById("shop-sidebar");
    var ct = document.getElementById("cat-toggle");
    var track = document.getElementById("pop-cats-track");
    if (track && track.classList.contains("is-loop")) {
      var prev = document.querySelector(".pop-prev");
      var next = document.querySelector(".pop-next");
      var count = parseInt(track.getAttribute("data-count"), 10) || 0;
      var cards = track.querySelectorAll(".pop-cat");
      var start = 0, end = 0, step = 90, wrapping = false, moving = false, scrollTimer = null;
      function measureCarousel() {
        if (!count || cards.length < count * 3) return;
        start = cards[count].offsetLeft;
        end = cards[count * 2].offsetLeft;
        if (cards[count + 1]) step = cards[count + 1].offsetLeft - cards[count].offsetLeft;
        track.scrollLeft = start;
      }
      function normalizeCarousel() {
        if (wrapping || !start || !end) return;
        var width = end - start;
        var target = track.scrollLeft;
        if (target >= end - 1) target -= width;
        else if (target < start - 1) target += width;
        else return;
        wrapping = true;
        track.style.scrollBehavior = "auto";
        track.scrollLeft = target;
        track.offsetWidth;
        track.style.scrollBehavior = "";
        wrapping = false;
      }
      function finishMove() {
        normalizeCarousel();
        moving = false;
      }
      function moveCarousel(direction) {
        if (moving || wrapping) return;
        moving = true;
        track.scrollBy({ left: direction * step, behavior: "smooth" });
        setTimeout(finishMove, 520);
      }
      function queueNormalize() {
        if (wrapping) return;
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function () {
          if (!moving) normalizeCarousel();
        }, 140);
      }
      function resizeCarousel() {
        if (!document.body.contains(track)) {
          window.removeEventListener("resize", resizeCarousel);
          clearTimeout(scrollTimer);
          return;
        }
        measureCarousel();
      }
      requestAnimationFrame(measureCarousel);
      window.addEventListener("resize", resizeCarousel, { passive: true });
      track.addEventListener("scroll", queueNormalize, { passive: true });
      if (prev) prev.addEventListener("click", function () { moveCarousel(-1); });
      if (next) next.addEventListener("click", function () { moveCarousel(1); });
    }
    if (ct) ct.addEventListener("click", function () {
      ct.closest(".filter-cat").classList.toggle("collapsed");
    });
    sb.addEventListener("click", function (e) {
      var caret = e.target.closest(".cat-caret:not(.leaf)");
      if (caret) {
        var node = caret.closest(".cat-node");
        node.classList.toggle("open");
        caret.classList.toggle("open");
        return;
      }
      var link = e.target.closest(".cat-link");
      if (link) {
        e.preventDefault(); e.stopPropagation();
        shopState.category = link.getAttribute("data-cat") || "";
        shopState.page = 1;
        loadShop();
        var node = link.closest(".cat-node");
        if (node && node.classList.contains("has-children")) {
          var open = node.classList.toggle("open");
          var c = node.querySelector(".cat-caret:not(.leaf)");
          if (c) c.classList.toggle("open", open);
        }
      }
    });
    sb.addEventListener("change", function (e) {
      if (e.target.classList.contains("attr-chk")) {
        var list = [];
        sb.querySelectorAll(".attr-chk:checked").forEach(function (cb) { list.push(cb.value); });
        shopState.attributes = list;
        shopState.page = 1;
        loadShop();
      } else if (e.target.classList.contains("stock-chk")) {
        var slist = [];
        sb.querySelectorAll(".stock-chk:checked").forEach(function (cb) { slist.push(cb.value); });
        shopState.stock = slist;
        shopState.page = 1;
        loadShop();
      } else if (e.target.classList.contains("cat-chk")) {
        var val = e.target.value || "";
        location.href = val ? U("categories/" + val) : U("shop");
      }
    });
    var ss = document.getElementById("sort-select");
    ss.addEventListener("change", function () { shopState.sort = ss.value; shopState.page = 1; loadShop(); });

    document.querySelectorAll(".view-btn").forEach(function (b) {
      b.addEventListener("click", function () {
        shopState.view = b.getAttribute("data-view");
        paintProducts();
        document.querySelectorAll(".view-btn").forEach(function (x) {
          x.classList.toggle("active", x.getAttribute("data-view") === shopState.view);
        });
      });
    });

    var sm = document.getElementById("shop-main");
    sm.addEventListener("click", function (e) {
      var a = e.target.closest(".page-link");
      if (!a) return;
      e.preventDefault(); e.stopPropagation();
      shopState.page = +a.getAttribute("data-page");
      loadShop();
    });
    var sp = document.getElementById("shop-pagination");
    if (sp) sp.addEventListener("click", function (e) {
      if (!e.target.closest(".load-more") || shopLoading) return;
      e.preventDefault();
      shopState.page++;
      loadShop();
    });
  }

  function paintProducts() {
    var el = document.getElementById("products-grid");
    if (!el) return;
    var emptyState='<div class="catalog-empty-state" role="status"><svg viewBox="0 0 160 132" aria-hidden="true"><path d="M27 30h82l18 67H45z" fill="#eaf4ff" stroke="#0b73e0" stroke-width="4" stroke-linejoin="round"/><path d="M20 20h18l8 22" fill="none" stroke="#163b68" stroke-width="5" stroke-linecap="round"/><circle cx="58" cy="111" r="8" fill="#fff" stroke="#163b68" stroke-width="4"/><circle cx="116" cy="111" r="8" fill="#fff" stroke="#163b68" stroke-width="4"/><circle cx="111" cy="42" r="25" fill="#fff" stroke="#4b93ed" stroke-width="4"/><path d="m129 60 18 18" stroke="#4b93ed" stroke-width="6" stroke-linecap="round"/><path d="M100 42h22M111 31v22" stroke="#9fc7f7" stroke-width="4" stroke-linecap="round"/></svg><h2>No products found</h2><p>Try another model or category, or remove the current filters.</p><a class="btn btn-primary" href="'+U('shop')+'">View all products</a></div>';
    if (THEME === "template-6" && window.FerryMain && shopState.view !== "grid") {
      el.className = "fm-catalog-products";
      el.innerHTML = allItems.length ? FerryMain.productTable(allItems, state.b2b) : emptyState;
      FerryMain.bindProducts(el, allItems);
      return;
    }
    el.className = "products view-" + (shopState.view || "list");
    el.innerHTML = allItems.length ? allItems.map(productCard).join("") : emptyState;
    bindAddButtons(el);
  }

  function loadShop() {
    var requestVersion = ++shopRequestVersion;
    shopLoading = true;
    var pager = document.getElementById("shop-pagination");
    if (pager) pager.classList.add("is-loading");
    var needsFacets=shopState.attributes.length>0||shopState.stock.length>0;
    var params = ["per_page=" + shopProductsPerPage];if(needsFacets)params.push("facets=1");
    if (shopState.q) params.push("q=" + enc(shopState.q));
    if (shopState.category) params.push("category=" + enc(shopState.category));
    if (shopState.sort) params.push("sort=" + enc(shopState.sort));
    shopState.attributes.forEach(function (a) { params.push("attribute[]=" + enc(a)); });
    shopState.stock.forEach(function (s) { params.push("stock[]=" + enc(s)); });
    params.push("page=" + shopState.page);

    getJSON(API + "/products?" + params.join("&")).then(function (data) {
      if (requestVersion !== shopRequestVersion || !document.getElementById("shop-main")) return;
      var items = data.items || [], total = data.total || 0;
      if (shopState.page === 1 || shopLoadingMode === "pagination") allItems = items; else allItems = allItems.concat(items);
      document.getElementById("result-count").textContent = total + " products";
      shopLoading = false;
      if (pager) pager.classList.remove("is-loading");
      renderLoadMore(total);
      paintProducts();
      if (shopState.page === 1 && !document.querySelector(".site-footer")) {
        app.insertAdjacentHTML("beforeend", footerHtml());
      }
      syncSidebar();
      if(needsFacets)syncAttributeFacets(data.facets || []);
      var sm = document.getElementById("shop-main");
      if (sm && shopState.page === 1 && window.scrollY > sm.offsetTop) window.scrollTo({ top: sm.offsetTop - 90, behavior: "smooth" });
    }).catch(function (e) {
      if (requestVersion !== shopRequestVersion || !document.getElementById("shop-main")) return;
      shopLoading = false;
      if (pager) pager.classList.remove("is-loading");
      var g = document.getElementById("products-grid");
      if (g) g.innerHTML = '<div class="loading">Failed: ' + esc(e.message) + "</div>";
    });
  }

  function renderLoadMore(total) {
    var box = document.getElementById("shop-pagination");
    if (!box) return;
    if (shopScrollObserver) { shopScrollObserver.disconnect(); shopScrollObserver = null; }
    if (total <= 0) { box.innerHTML = ""; box.hidden = true; return; }
    box.hidden = false;
    var totalPages = Math.max(1, Math.ceil(total / shopProductsPerPage));
    if (shopLoadingMode === "pagination") {
      if (totalPages <= 1) { box.innerHTML = ""; box.hidden = true; return; }
      var start=Math.max(1,shopState.page-2),end=Math.min(totalPages,start+4);start=Math.max(1,end-4);var links='';
      links+='<button type="button" class="page-link page-arrow"'+(shopState.page>1?' data-page="'+(shopState.page-1)+'"':' disabled')+' aria-label="Previous page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6-6 6 6 6"/></svg></button>';
      for(var p=start;p<=end;p++)links+='<button type="button" class="page-link'+(p===shopState.page?' active':'')+'" data-page="'+p+'"'+(p===shopState.page?' aria-current="page"':'')+'>'+p+'</button>';
      links+='<button type="button" class="page-link page-arrow"'+(shopState.page<totalPages?' data-page="'+(shopState.page+1)+'"':' disabled')+' aria-label="Next page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6"/></svg></button>';
      box.innerHTML='<nav class="pagination-shell" aria-label="Product pages"><span class="pagination-status"><span>Page</span><strong>'+shopState.page+'</strong><span>of '+totalPages+'</span></span><span class="pagination-divider" aria-hidden="true"></span><div class="pagination-pages">'+links+'</div></nav>'; return;
    }
    var hasMore=allItems.length < total;
    var remaining=Math.max(0,total-allItems.length),nextCount=Math.min(shopProductsPerPage,remaining);
    if(shopLoadingMode === "infinite") {
      box.innerHTML=hasMore?'<div class="infinite-sentinel" role="status"><span></span><small>Scroll to load '+nextCount+' more</small></div>':'<div class="catalog-complete">All '+total+' products loaded</div>';
      var sentinel=box.querySelector('.infinite-sentinel');
      if(sentinel && "IntersectionObserver" in window){shopScrollObserver=new IntersectionObserver(function(entries){if(entries[0].isIntersecting&&!shopLoading){shopState.page++;loadShop();}},{rootMargin:'280px 0px'});shopScrollObserver.observe(sentinel);}
      else if(sentinel){box.innerHTML='<button type="button" class="load-more">Load more</button>';}
      return;
    }
    box.innerHTML = hasMore ? '<button type="button" class="load-more" id="load-more"><span>Load '+nextCount+' more</span><small>'+remaining+' remaining</small></button>' : '<div class="catalog-complete">All '+total+' products loaded</div>';
  }

  function syncSidebar() {
    var sb = document.getElementById("shop-sidebar");
    if (!sb) return;
    Array.prototype.forEach.call(sb.querySelectorAll(".cat-chk"), function (cb) {
      cb.checked = (cb.value === shopState.category);
    });
    Array.prototype.forEach.call(sb.querySelectorAll(".attr-chk"), function (cb) {
      cb.checked = shopState.attributes.indexOf(cb.value) >= 0;
    });
    Array.prototype.forEach.call(sb.querySelectorAll(".stock-chk"), function (cb) {
      cb.checked = shopState.stock.indexOf(cb.value) >= 0;
    });
    var crumb = document.getElementById("crumb-cat");
    if (crumb) {
      crumb.textContent = (shopState.category && catById[catSlugToId[shopState.category]]) ? catById[catSlugToId[shopState.category]].name : "";
    }
    var ss = document.getElementById("sort-select");
    if (ss) ss.value = shopState.sort || "";
  }

  function syncAttributeFacets(facets) {
    var sb = document.getElementById("shop-sidebar");
    if (!sb) return;
    var available = {};
    facets.forEach(function (facet) {
      available[String(facet.attribute_slug) + ":" + String(facet.value_slug)] = parseInt(facet.product_count, 10) || 0;
    });
    Array.prototype.forEach.call(sb.querySelectorAll(".attr-block"), function (block) {
      var visible = 0;
      Array.prototype.forEach.call(block.querySelectorAll(".attr-chk"), function (checkbox) {
        var count = available[checkbox.value] || 0;
        var row = checkbox.closest("li");
        if (row) row.hidden = count < 1;
        if (count > 0) visible += 1;
      });
      block.hidden = visible < 1;
    });
    Array.prototype.forEach.call(sb.querySelectorAll(".attribute-widget"), function (widget) {
      widget.hidden = !Array.prototype.some.call(widget.querySelectorAll(".attr-block"), function (block) { return !block.hidden; });
    });
  }

  /* ---------------- PRODUCT DETAIL ---------------- */
  function renderProduct(slug) {
    loading();
    getJSON(API + "/products/" + enc(slug)).then(function (d) {
      var p = d.product;
      var prices = d.prices || [];
      var rolePrice = prices.length ? parseFloat(prices[0].price) : null;
      var roleCurrency = prices.length ? prices[0].currency : CUR;
      var roleLabel = prices.length ? (prices[0].role_label || "Your account price") : "";
      var minimumQty = Math.max(1, parseInt((prices[0] || {}).minimum_quantity || p.minimum_order_qty || 1, 10) || 1);
      var quantityStep = Math.max(1, parseInt((prices[0] || {}).quantity_step || p.quantity_step || 1, 10) || 1);
      var cats = d.categories || [];
      var attrs = d.attributes || [];
      var media = (d.media && d.media.length) ? d.media : [{ file_path: p.image, alt: p.name }];
      var imgs = media.map(function (m) { return m.file_path; });
      if (p.image && imgs.indexOf(p.image) < 0) imgs.unshift(p.image);
      if (!imgs.length) imgs = [PLACEHOLDER];
      var out = (p.stock_status === "outofstock");

      var thumbHtml = imgs.map(function (src, i) {
        return '<img src="' + esc(src) + '" class="' + (i === 0 ? "active" : "") + '" data-img="' + esc(src) + '">';
      }).join("");

      var attrRows = attrs.map(function (a) {
        return "<tr><td>" + esc(a.attr) + "</td><td>" + esc(a.value) + "</td></tr>";
      }).join("");

      var priceBlock = state.b2b
        ? '<section class="pd-price-card"><div><span class="pd-price-label">' + esc(roleLabel || "Your account price") + '</span><strong class="price-big">' + (rolePrice != null ? money(rolePrice,roleCurrency) : "Contact us") + '</strong><small>Role pricing applied automatically · VAT included</small></div><span class="pd-price-check" aria-hidden="true">✓</span></section>'
        : '<section class="pd-price-card pd-price-card--locked"><span class="pd-lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><strong>Sign in to view your price</strong><small>Prices are personalized for each approved customer role.</small></div><a class="btn btn-primary" href="' + U("account") + '">Sign in</a></section>';
      var stockBlock = '<div class="pd-stockline ' + (out ? 'is-out' : 'is-in') + '"><span class="pd-stock-dot"></span><strong>' + (out ? 'Out of stock' : esc(p.stock) + ' in stock') + '</strong><small>' + (out ? 'Contact us for availability' : 'Live warehouse availability') + '</small></div>';
      var purchaseBlock = !state.b2b
        ? '<div class="pd-guest-note">Create or sign in to an approved account to order at your assigned price.</div>'
        : (out ? '<div class="pd-unavailable">This item is currently unavailable for ordering.</div>' : '<div class="pd-purchase"><div class="pd-quantity"><span>Quantity</span><div class="pd-stepper"><button type="button" id="pd-minus" aria-label="Decrease quantity">−</button><input type="number" id="pd-qty" value="' + minimumQty + '" min="' + minimumQty + '" step="' + quantityStep + '" inputmode="numeric"><button type="button" id="pd-plus" aria-label="Increase quantity">+</button></div><small>Minimum ' + minimumQty + (quantityStep > 1 ? ' · step ' + quantityStep : '') + '</small></div><div class="pd-actions"><button class="btn btn-primary" id="pd-add" data-add="' + p.id + '">Add to cart</button><button class="btn wl-heart-btn" id="pd-wishlist" data-wl="' + p.id + '" data-slug="' + esc(p.slug) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 5.6a5.5 5.5 0 0 0-7.8 0L12 6.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 22l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg><span>Wishlist</span></button></div></div>');

      app.innerHTML =
        '<div class="container pd-page">' +
        '<div class="breadcrumb"><a href="' + U("") + '">Home</a> / <a href="' + U("shop") + '">Shop</a> / ' + esc(p.name) + "</div>" +
        '<div class="single-product ferry-main-product-detail">' +
        '<div class="gallery"><div class="main-image"><img id="main-img" src="' + esc(imgs[0]) + '" alt="' + esc(p.name) + '"></div>' +
        '<div class="thumbs">' + thumbHtml + "</div></div>" +
        '<div class="summary"><span class="pd-eyebrow">Professional repair component</span>' +
        '<h1 class="product_title">' + esc(p.name) + "</h1>" +
        '<div class="pd-meta"><span>SKU <b>' + esc(p.sku || '—') + '</b></span>' + (cats.length ? '<a href="' + U("categories/" + cats[0].slug) + '">' + esc(cats[0].name) + '</a>' : '') + '</div>' +
        priceBlock + stockBlock + purchaseBlock +
        '<div class="pd-assurance"><span><b>✓</b> Role based pricing</span><span><b>✓</b> Live stock</span><span><b>✓</b> Secure checkout</span></div>' +
        (attrRows ? '<div class="attrs"><table class="attr-table">' + attrRows + "</table></div>" : "") +
        "</div></div>" +

        '<div class="tabs"><div class="tabs-nav"><button class="active" data-tab="desc">Description</button>' +
        (cats.length ? '<button data-tab="cat">Categories</button>' : "") + "</div>" +
        '<div class="tab-panel active" id="tab-desc">' + (p.description ? esc(p.description) : "<p>No description available.</p>") + "</div>" +
        (cats.length ? '<div class="tab-panel" id="tab-cat">' + cats.map(function (c) { return '<a href="' + U("categories/" + c.slug) + '">' + esc(c.name) + "</a>"; }).join(", ") + "</div>" : "") +
        "</div>" +

        '<div class="related section"><div class="section-head"><h2>Related Products</h2></div><div class="products" id="related"></div></div>' +
        "</div>";

      Array.prototype.forEach.call(app.querySelectorAll(".thumbs img"), function (t) {
        t.addEventListener("click", function () {
          document.getElementById("main-img").src = t.getAttribute("data-img");
          app.querySelectorAll(".thumbs img").forEach(function (x) { x.classList.remove("active"); });
          t.classList.add("active");
        });
      });
      Array.prototype.forEach.call(app.querySelectorAll(".tabs-nav button"), function (b) {
        b.addEventListener("click", function () {
          app.querySelectorAll(".tabs-nav button").forEach(function (x) { x.classList.remove("active"); });
          app.querySelectorAll(".tab-panel").forEach(function (x) { x.classList.remove("active"); });
          b.classList.add("active");
          document.getElementById("tab-" + b.getAttribute("data-tab")).classList.add("active");
        });
      });
      if (!out && state.b2b) {
        document.getElementById("pd-minus").addEventListener("click", function () { var input=document.getElementById("pd-qty");input.value=Math.max(minimumQty,(parseInt(input.value,10)||minimumQty)-quantityStep); });
        document.getElementById("pd-plus").addEventListener("click", function () { var input=document.getElementById("pd-qty");input.value=Math.max(minimumQty,(parseInt(input.value,10)||minimumQty)+quantityStep); });
        document.getElementById("pd-add").addEventListener("click", function () {
          var qty = Math.max(minimumQty,parseInt(document.getElementById("pd-qty").value, 10) || minimumQty);
          addToCart({ id: p.id, slug: p.slug, name: p.name, image: imgs[0], sku: p.sku, price: (state.b2b ? rolePrice : null), currency:roleCurrency }, qty, this);
        });
        document.getElementById("pd-wishlist").addEventListener("click", function () {
          toggleWishlist({ id: p.id, slug: p.slug, name: p.name, image: imgs[0], sku: p.sku, price: (state.b2b ? (p.price != null ? p.price : null) : null) });
        });
      }
      syncCartButtons();

      function appendFooter() { if (!document.querySelector(".site-footer")) app.insertAdjacentHTML("beforeend", footerHtml()); }
      if (cats.length) {
        getJSON(API + "/products?category=" + enc(cats[0].slug) + "&page=1").then(function (rel) {
          var relEl = document.getElementById("related");
          if (relEl) relEl.innerHTML = (rel.items || []).filter(function (x) { return x.id !== p.id; }).slice(0, 4).map(productCard).join("") || '<div class="empty-cart">No related products.</div>';
          bindAddButtons(relEl);
          appendFooter();
        }).catch(appendFooter);
      } else { appendFooter(); }
    }).catch(function (e) { app.innerHTML = '<div class="loading">Product not found: ' + esc(e.message) + "</div>"; });
  }

  /* ---------------- CART PAGE ---------------- */
  function renderCart() {
    if (!state.cart.length) {
      app.innerHTML = '<div class="container cart-page">' + breadcrumb([{ label: "Shop", href: U("shop") }, { label: "Cart" }]) + '<div class="empty-cart"><span class="empty-cart__icon" aria-hidden="true">&#128722;</span><h1>Your cart is empty</h1><p>Browse the catalogue and add the parts you need.</p><a class="btn btn-primary" href="' + U("shop") + '">Continue shopping</a></div></div>' + footerHtml();
      return;
    }
    var missingMeta = state.cart.filter(function (item) { return !item._metaReady; });
    if (missingMeta.length && !renderCart._metaLoading) {
      renderCart._metaLoading = true;
      var idsQuery = missingMeta.map(function (item) { return "ids[]=" + enc(item.id); }).join("&");
      getJSON(API + "/products?" + idsQuery).then(function (result) {
        var byId = {};
        (result.items || []).forEach(function (product) { byId[String(product.id)] = product; });
        state.cart.forEach(function (item) {
          var product = byId[String(item.id)];
          if (product) {
            item.quality = product.variant || "";
            item.category = product.category || "";
            item.color = product.color || "";
          }
          item._metaReady = true;
        });
        saveCart();
      }).catch(function () {
        state.cart.forEach(function (item) { item._metaReady = true; });
      }).finally(function () {
        renderCart._metaLoading = false;
        renderCart();
      });
      return;
    }
    var rows = state.cart.map(function (i) {
      var unit = state.b2b ? (i.price != null ? money(i.price) : "Updating…") : "";
      var line = state.b2b ? (i.price != null ? money(i.price * i.qty) : "—") : '<a class="mc-login" href="' + U("account") + '">Login for price</a>';
      return '<article class="cart-row">' +
        '<div class="cart-row__primary"><a class="cart-row__image" href="' + U("product/" + i.slug) + '"><img src="' + esc(i.image || PLACEHOLDER) + '" alt="' + esc(i.name) + '"></a>' +
        '<div class="cart-row__product"><div class="cart-row__title"><a class="cart-row__name" href="' + U("product/" + i.slug) + '" title="' + esc(i.name) + '">' + esc(i.name) + '</a><button type="button" class="cart-row__toggle" aria-expanded="false" aria-label="Show full product details"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg></button></div>' +
        '<div class="cart-row__meta"><span><b>SKU</b> ' + esc(i.sku || "—") + '</span><span><b>Quality</b> ' + esc(i.quality || "—") + '</span><span><b>Category</b> ' + esc(i.category || "—") + '</span>' +
        (i.color ? '<span class="cart-row__color" title="' + esc(i.color) + '" aria-label="Color: ' + esc(i.color) + '"><i style="--cart-swatch:' + esc(colorSwatch(i.color)) + '"></i></span>' : '') + '</div>' +
        (unit ? '<span class="cart-row__unit">' + unit + ' each</span>' : '') + '</div></div><div class="cart-row__commerce">' +
        '<div class="cart-row__quantity"><span class="cart-row__label">Quantity</span><div class="cart-stepper"><button type="button" data-cart-minus="' + i.id + '" aria-label="Decrease quantity">−</button><input class="qty" type="number" min="1" inputmode="numeric" value="' + i.qty + '" data-qty="' + i.id + '" aria-label="Quantity"><button type="button" data-cart-plus="' + i.id + '" aria-label="Increase quantity">+</button></div></div>' +
        '<div class="cart-row__total"><span class="cart-row__label">Total</span><strong>' + line + '</strong></div></div>' +
        '<button class="cart-row__remove" type="button" data-rm="' + i.id + '" aria-label="Remove ' + esc(i.name) + '" title="Remove item"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3m2 0-1 13H8L7 7m3 4v5m4-5v5"/></svg></button>' +
        '</article>';
    }).join("");
    var count = state.cart.reduce(function (s, i) { return s + i.qty; }, 0);
    var total = state.cart.reduce(function (s, i) { return s + (i.price != null ? i.price * i.qty : 0); }, 0);
    var totalHtml = state.b2b ? money(total) : '<a class="mc-login" href="' + U("account") + '">Login for price</a>';
    app.innerHTML =
      '<div class="container cart-page ferry-main-cart">' +
      '<div class="cart-layout"><main class="cart-panel"><div class="cart-panel__head"><span>Shopping cart</span><button class="cart-clear" id="clear-cart" type="button">Clear cart</button></div><div class="cart-columns" aria-hidden="true"><span></span><div><span>Quantity</span><span>Total</span></div><span></span></div><div class="cart-list">' + rows + '</div>' +
      '<div class="cart-actions"><a class="cart-continue" href="' + U("shop") + '"><span aria-hidden="true">←</span> Continue shopping</a><span>Quantities update automatically</span></div></main>' +
      '<aside class="cart-summary"><div class="cart-summary__head"><span>Order summary</span><span>' + count + ' items</span></div>' +
      '<div class="cart-summary__row"><span>Subtotal</span><strong>' + totalHtml + '</strong></div>' +
      '<div class="cart-summary__row"><span>Shipping</span><small>Calculated at checkout</small></div>' +
      '<div class="cart-summary__total"><span>Total</span><strong>' + totalHtml + '</strong></div>' +
      '<button class="cart-checkout" id="checkout-btn" type="button">Proceed to checkout <span aria-hidden="true">→</span></button>' +
      '<div class="cart-assurance"><span>&#10003; Secure checkout</span><span>&#10003; Business pricing</span><span>&#10003; Swiss support</span></div>' +
      '</aside></div></div>' + footerHtml();

    Array.prototype.forEach.call(app.querySelectorAll("[data-qty]"), function (inp) {
      inp.addEventListener("change", function () { updateQty(+this.getAttribute("data-qty"), +this.value); });
    });
    Array.prototype.forEach.call(app.querySelectorAll("[data-cart-minus]"), function (b) {
      b.addEventListener("click", function () { var it = state.cart.find(function (x) { return x.id === +b.getAttribute("data-cart-minus"); }); if (it) updateQty(it.id, Math.max(1, it.qty - 1)); });
    });
    Array.prototype.forEach.call(app.querySelectorAll("[data-cart-plus]"), function (b) {
      b.addEventListener("click", function () { var it = state.cart.find(function (x) { return x.id === +b.getAttribute("data-cart-plus"); }); if (it) updateQty(it.id, it.qty + 1); });
    });
    Array.prototype.forEach.call(app.querySelectorAll("[data-rm]"), function (b) {
      b.addEventListener("click", function () { removeFromCart(+b.getAttribute("data-rm")); });
    });
    Array.prototype.forEach.call(app.querySelectorAll(".cart-row__toggle"), function (button) {
      button.addEventListener("click", function () { var row=button.closest('.cart-row'),expanded=row.classList.toggle('is-expanded');button.setAttribute('aria-expanded',expanded?'true':'false');button.setAttribute('aria-label',expanded?'Hide full product details':'Show full product details'); });
    });
    document.getElementById("clear-cart").addEventListener("click", function () {
      if (!window.confirm("Remove all products from your cart?")) return;
      state.cart = []; saveCart(); renderCartHeader(); renderDrawer(); renderCart();
    });
    document.getElementById("checkout-btn").addEventListener("click", function () {
      if (!state.b2b) { openLogin(); return; }
      pushUrl(U("checkout"));
    });
  }

  /* ---------------- CHECKOUT ---------------- */
  function renderCheckout(query) {
    query = query || {};if(query.payment_cancelled)sessionStorage.removeItem("ft_checkout_key");
    if (!state.customer || !state.b2b) { pushUrl(U("account")); setTimeout(function () { if (!state.customer) openLogin("login"); }, 0); return; }
    if (!state.cart.length && !query.stripe_session && !query.paypal_return && !query.paypal_legacy_return) { pushUrl(U("cart")); return; }
    loading();
    function completePayment(payload) {
      postJSON(API + "/checkout/complete", payload).then(function () {
        state.cart = []; saveCart(); renderCartHeader(); renderDrawer(); pushUrl(U("account?pane=orders&payment=success"));
      }).catch(function (error) { app.innerHTML = '<div class="container checkout-result checkout-result--error"><h1>Payment verification failed</h1><p>' + esc(error.message) + '</p><a class="btn" href="' + U("checkout") + '">Return to checkout</a></div>' + footerHtml(); });
    }
    if (query.stripe_session) { completePayment({ stripe_session:query.stripe_session }); return; }
    if (query.paypal_return && query.token) { completePayment({ paypal_order:query.token }); return; }
    if (query.paypal_legacy_return && query.tx) { completePayment({ paypal_standard_tx:query.tx }); return; }
    getJSON(API + "/checkout/bootstrap").then(function (data) {
      marketCountries=fullMarketCountries(data.countries||marketCountries);
      var addresses=data.addresses||[], first=addresses[0]||{};
      var total=state.cart.reduce(function(sum,item){return sum+((parseFloat(item.price)||0)*item.qty);},0);
      var addressCards=addresses.length?addresses.map(function(a,index){return '<button type="button" class="checkout-address'+(index===0?' is-selected':'')+'" data-address-index="'+index+'"><span class="checkout-address__check">&#10003;</span><strong>'+esc(a.name||"Saved address")+'</strong><span>'+esc(a.company||"")+'</span><span>'+esc(a.line1)+(a.line2?', '+esc(a.line2):'')+'</span><span>'+esc(a.postcode)+' '+esc(a.city)+', '+esc(marketCountries[a.country]||a.country)+'</span></button>';}).join(""):'<div class="checkout-no-address">No saved address yet. This address will be saved securely for your next order.</div>';
      var itemRows=state.cart.map(function(item){return '<div class="checkout-item" data-checkout-item="'+item.id+'"><img src="'+esc(item.image||PLACEHOLDER)+'" alt=""><div class="checkout-item__copy"><div class="checkout-item__title"><strong title="'+esc(item.name)+'">'+esc(item.name)+'</strong><button type="button" class="checkout-item__toggle" aria-expanded="false" aria-label="Show full product details"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg></button></div><span class="checkout-item__details">'+item.qty+' × '+money(item.price||0,item.currency)+(item.color?' · '+esc(item.color):'')+(item.sku?' · SKU '+esc(item.sku):'')+'</span><div class="checkout-item__commerce"><div class="checkout-item__quantity" aria-label="Change quantity"><button type="button" data-checkout-minus aria-label="Decrease quantity">−</button><input type="number" min="1" inputmode="numeric" value="'+item.qty+'" aria-label="Quantity"><button type="button" data-checkout-plus aria-label="Increase quantity">+</button></div><b>'+money((item.price||0)*item.qty,item.currency)+'</b></div></div><button type="button" class="checkout-item__remove" aria-label="Remove '+esc(item.name)+'"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M9 7V4h6v3m2 0-1 13H8L7 7m3 4v5m4-5v5"/></svg></button></div>';}).join("");
      var gd=data.gateway_details||{},gatewayIds=Object.keys(data.gateways||{}).filter(function(id){return data.gateways[id]&&gd[id];}),gateways=gatewayIds.length;
      CUR=data.currency||CUR;
      var shippingMethods=data.shipping_methods||[],taxInfo=data.tax||{},shippingMarkup=shippingMethods.map(function(m,i){var free=Number(m.free_from)>0&&total>=Number(m.free_from),price=free?0:Number(m.price||0);return '<label class="checkout-gateway"><input type="radio" name="shipping_method" value="'+esc(m.id)+'" data-price="'+price+'" '+(i===0?'checked':'')+'><span><strong>'+esc(m.name)+'</strong><small data-shipping-price="'+esc(m.id)+'">'+(free?'Free for this order':money(price))+'</small></span></label>';}).join('');
      var gatewayMarkup=gatewayIds.map(function(id){var d=gd[id]||{},enabled=!!data.gateways[id],after=d.image_position==='after',mark=gatewayMedia(id,d),copy='<span class="checkout-gateway__copy"><strong>'+esc(d.title||id.replace('_',' '))+'</strong><small>'+esc(d.description||'Secure payment option')+'</small></span>';return '<label class="checkout-gateway checkout-gateway--'+id+(after?' checkout-gateway--image-after':'')+(!enabled?' is-disabled':'')+'"><input type="radio" name="payment_method" value="'+id+'" '+(!enabled?'disabled':'')+'>'+(after?'':mark)+copy+(after?mark:'')+'<em>'+esc(String(d.mode||'offline').toUpperCase())+'</em><span class="checkout-gateway__selected" aria-hidden="true">✓</span></label>';}).join('');
      app.innerHTML='<div class="container checkout-page ferry-main-checkout">'+breadcrumb([{label:"Cart",href:U("cart")},{label:"Checkout"}])+
        '<header class="checkout-head"><div><span>Secure checkout</span><h1>Complete your order</h1><p>Choose a saved address or enter a new delivery address.</p></div><div class="checkout-steps"><b>1</b><span>Delivery</span><i></i><b>2</b><span>Payment</span></div></header>'+
        (query.payment_cancelled?'<div class="checkout-notice">Payment was cancelled. Your cart and address are still saved.</div>':'')+
        '<form id="checkout-form" class="checkout-layout"><main class="checkout-main">'+
        '<section class="checkout-card"><div class="checkout-card__head"><div><span>01</span><h2>Saved addresses</h2></div><small>Select one to autofill</small></div><div class="checkout-addresses">'+addressCards+'</div></section>'+
        '<section class="checkout-card"><div class="checkout-card__head"><div><span>02</span><h2>Shipping address</h2></div><small>Fields marked * are required</small></div><div class="checkout-fields">'+
        '<label class="checkout-field checkout-field--wide"><span>Full name *</span><input name="name" required autocomplete="name" value="'+esc(first.name||((data.customer.first_name||'')+' '+(data.customer.last_name||'')).trim())+'"></label><label class="checkout-field"><span>Company</span><input name="company" autocomplete="organization" value="'+esc(first.company||data.customer.company||'')+'"></label><label class="checkout-field"><span>Phone *</span><input name="phone" required autocomplete="tel" value="'+esc(first.phone||'')+'"></label>'+
        '<label class="checkout-field checkout-field--wide"><span>Address line 1 *</span><input name="line1" required autocomplete="address-line1" value="'+esc(first.line1||'')+'"></label><label class="checkout-field"><span>Address line 2</span><input name="line2" autocomplete="address-line2" value="'+esc(first.line2||'')+'"></label>'+
        '<label class="checkout-field"><span>City *</span><input name="city" required autocomplete="address-level2" value="'+esc(first.city||'')+'"></label><label class="checkout-field"><span>State / Canton</span><input name="state" autocomplete="address-level1" value="'+esc(first.state||'')+'"></label><label class="checkout-field"><span>Postcode *</span><input name="postcode" required autocomplete="postal-code" value="'+esc(first.postcode||'')+'"></label><label class="checkout-field"><span>Country *</span><select name="country" required autocomplete="country">'+countryOptions(first.country||'CH',marketCountries)+'</select></label></div><p class="checkout-save-note"><span>&#10003;</span> New addresses are saved automatically. Exact duplicates are never created.</p></section><section class="checkout-card"><div class="checkout-card__head"><div><span>03</span><h2>Delivery method</h2></div></div><div class="checkout-gateways" id="checkout-shipping-methods">'+shippingMarkup+'</div></section>'+ 
        '</main><aside class="checkout-order"><div class="checkout-card__head"><div><span>04</span><h2>Your order</h2></div><small>'+state.cart.reduce(function(s,i){return s+i.qty;},0)+' items</small></div><div class="checkout-items">'+itemRows+'</div><div class="checkout-coupon"><label for="checkout-coupon">Coupon code</label><div><input id="checkout-coupon" name="coupon_code" autocomplete="off" placeholder="Enter code"><button type="button" id="apply-coupon">Apply</button></div><small id="coupon-message"></small></div><div class="checkout-total-row"><span>Subtotal</span><b>'+money(total)+'</b></div><div class="checkout-total-row checkout-discount" hidden><span>Discount</span><b id="checkout-discount">—</b></div><div class="checkout-total-row"><span>Shipping</span><b id="checkout-shipping">—</b></div><div class="checkout-total-row"><span>VAT</span><b id="checkout-tax">—</b></div><div class="checkout-grand-total"><span>Total</span><b id="checkout-total">'+money(total)+'</b></div>'+ 
        '<div class="checkout-payment-side"><div class="checkout-payment-side__head"><strong>Payment method</strong><small>Secure checkout</small></div><div class="checkout-gateways">'+
        gatewayMarkup+'</div>'+(gateways?'':'<div class="checkout-gateway-warning">No payment gateway is currently available. Please contact support.</div>')+'</div>'+
        '<button class="checkout-place" id="place-order" type="submit" hidden>Place order securely <span>→</span></button><div class="checkout-message" id="checkout-message" role="status"></div><div class="checkout-security">&#128274; Encrypted payment · No card data stored by Ferry Telecom</div></aside></form></div>'+footerHtml();
      var deliverySection=document.getElementById('checkout-shipping-methods').closest('section');
      deliverySection.className='checkout-delivery-side';
      document.querySelector('.checkout-payment-side').before(deliverySection);
      var orderPanel=document.querySelector('.checkout-order'),summary=document.createElement('details');
      summary.className='checkout-summary';summary.open=true;
      summary.innerHTML='<summary>Order summary <span aria-hidden="true">⌄</span></summary><div class="checkout-summary-content"></div>';
      orderPanel.prepend(summary);
      var summaryContent=summary.querySelector('.checkout-summary-content');
      while(summary.nextElementSibling && summary.nextElementSibling!==deliverySection)summaryContent.append(summary.nextElementSibling);
      summary.addEventListener('toggle',function(){if(window.innerWidth>960&&!summary.open)summary.open=true;});
      document.getElementById('place-order').innerHTML='Checkout <span aria-hidden="true">→</span>';
      var customerPanel=document.querySelector('.checkout-main');
      if(!addresses.length)customerPanel.querySelector('.checkout-card').remove();
      customerPanel.querySelector('.checkout-card__head h2').textContent='Delivery details';
      document.querySelector('.checkout-head h1').textContent='Checkout';
      document.querySelector('.checkout-head p').textContent='Your details, delivery and payment — all in one place.';
      document.querySelectorAll('.checkout-item').forEach(function(row){
        var item=state.cart.find(function(i){return String(i.id)===row.getAttribute('data-checkout-item');});
        var thumbnail=document.createElement('div');thumbnail.className='checkout-product-preview';
        thumbnail.setAttribute('data-quantity',item?item.qty:1);
        var image=row.querySelector('img');image.before(thumbnail);thumbnail.append(image);
        var toggle=row.querySelector('.checkout-item__toggle');if(toggle)toggle.onclick=function(){var expanded=row.classList.toggle('is-expanded');toggle.setAttribute('aria-expanded',expanded?'true':'false');toggle.setAttribute('aria-label',expanded?'Hide full product details':'Show full product details');};
        var qtyInput=row.querySelector('.checkout-item__quantity input');
        function setCheckoutQty(value){if(!item)return;value=Math.max(1,parseInt(value,10)||1);item.qty=value;updateQty(item.id,value);qtyInput.value=value;thumbnail.setAttribute('data-quantity',value);requestQuote();}
        var minus=row.querySelector('[data-checkout-minus]');if(minus)minus.onclick=function(){setCheckoutQty(item.qty-1);};
        var plus=row.querySelector('[data-checkout-plus]');if(plus)plus.onclick=function(){setCheckoutQty(item.qty+1);};
        if(qtyInput)qtyInput.onchange=function(){setCheckoutQty(qtyInput.value);};
        var remove=row.querySelector('.checkout-item__remove');if(remove)remove.onclick=function(){removeFromCart(+row.getAttribute('data-checkout-item'));row.remove();if(!state.cart.length)pushUrl(U('cart'));else requestQuote();};
      });
      var form=document.getElementById("checkout-form"), country=form.elements.country; if(first.country)country.value=first.country;enhanceCountrySelect(country);
      var quoteReady=false,quoteTimer=null,quoteVersion=0,quotedCurrency='',quotedTotal=null,autoRemovedCount=0;
      function updateTotals(){if(quoteReady)return;document.getElementById('checkout-shipping').textContent='Calculated securely';document.getElementById('checkout-tax').textContent='—';document.getElementById('checkout-total').textContent='—';}
function requestQuote(){var requestVersion=++quoteVersion;clearTimeout(quoteTimer);quoteReady=false;quotedCurrency='';quotedTotal=null;document.getElementById("place-order").hidden=true;var gateway=form.querySelector('input[name="payment_method"]:checked'),shipping=form.querySelector('input[name="shipping_method"]:checked');var complete=form.checkValidity()&&!!gateway&&!!shipping;if(!form.elements.country.value){updateTotals();return;}quoteTimer=setTimeout(function(){var address={};["name","company","phone","line1","line2","city","state","postcode","country"].forEach(function(k){address[k]=form.elements[k].value.trim();});var message=document.getElementById('checkout-message'),couponCode=form.elements.coupon_code.value.trim();message.textContent='Updating currency and totals…';message.classList.remove('is-error');postJSON(API+'/checkout/quote',{address:address,preview:!complete,payment_method:gateway?gateway.value:"",shipping_method:shipping?shipping.value:"",coupon_code:couponCode,items:state.cart.map(function(i){return{id:i.id,qty:i.qty};})}).then(function(q){if(requestVersion!==quoteVersion||!form.isConnected)return;var stale=(q.items||[]).filter(function(item){return item.validation_code==='unavailable';});if(stale.length){var staleIds=stale.map(function(item){return +item.id;});autoRemovedCount+=staleIds.length;state.cart=state.cart.filter(function(item){return staleIds.indexOf(+item.id)<0;});saveCart();renderCartHeader();renderDrawer();staleIds.forEach(function(id){var row=form.querySelector('[data-checkout-item="'+id+'"]');if(row)row.remove();});if(!state.cart.length){pushUrl(U('cart'));return;}requestQuote();return;}cartPriceVersion++;CUR=q.currency||CUR;(q.items||[]).forEach(function(p){var item=state.cart.find(function(i){return +i.id===+p.id;});if(item){item.price=p.unit_price;item.currency=CUR;}});saveCart();renderCartHeader();renderDrawer();quotedCurrency=CUR;quotedTotal=Number(q.totals.total);(q.items||[]).forEach(function(item){var row=form.querySelector('[data-checkout-item="'+item.id+'"]');if(row){row.querySelector('span').textContent=item.qty+' × '+money(item.unit_price,CUR);row.querySelector('b').textContent=money(item.line_total,CUR);row.classList.toggle('is-invalid',!!item.validation_error);var warning=row.querySelector('.checkout-item__warning');if(item.validation_error&&!warning){warning=document.createElement('div');warning.className='checkout-item__warning';warning.innerHTML='<span>'+esc(item.validation_error)+'</span><button type="button">Remove</button>';row.append(warning);warning.querySelector('button').onclick=function(){removeFromCart(+item.id);row.remove();if(!state.cart.length)pushUrl(U('cart'));else requestQuote();};}else if(warning&&!item.validation_error)warning.remove();}});var shippingBox=document.getElementById('checkout-shipping-methods');shippingBox.innerHTML=(q.shipping_methods||[]).map(function(m){var selected=m.id===q.selected_shipping_method;return '<label class="checkout-gateway"><input type="radio" name="shipping_method" value="'+esc(m.id)+'" data-price="'+esc(m.price)+'" '+(selected?'checked':'')+'><span><strong>'+esc(m.name)+'</strong><small data-shipping-price="'+esc(m.id)+'">'+(Number(m.price)===0?'Free':money(m.price,CUR))+'</small></span></label>';}).join('');document.querySelector('.checkout-total-row b').textContent=money(q.totals.subtotal,CUR);var discountRow=document.querySelector('.checkout-discount'),couponMessage=document.getElementById('coupon-message');discountRow.hidden=!q.coupon;if(q.coupon){document.getElementById('checkout-discount').textContent='− '+money(q.totals.discount,CUR);couponMessage.textContent=q.coupon.code+' applied';}else couponMessage.textContent='';document.getElementById('checkout-shipping').textContent=money(q.totals.shipping,CUR);document.getElementById('checkout-tax').textContent=money(q.totals.tax,CUR)+(q.totals.tax_inclusive?' included':'');document.getElementById('checkout-total').textContent=money(q.totals.total,CUR);var errors=q.validation_errors||[],removed=autoRemovedCount;autoRemovedCount=0;quoteReady=complete&&!errors.length;message.textContent=errors.length?'Review the cart item warnings above.':(removed?'Removed '+removed+' unavailable item'+(removed===1?'':'s')+'. ':'')+'Prices confirmed in '+CUR;message.classList.toggle('is-error',!!errors.length);document.getElementById("place-order").hidden=!quoteReady;}).catch(function(error){if(requestVersion!==quoteVersion||!form.isConnected)return;document.getElementById("place-order").hidden=true;message.textContent=error.message;message.classList.add('is-error');document.getElementById('coupon-message').textContent='';quoteReady=false;});},350);}
      function validate(){updateTotals();requestQuote();}
      function fill(a){["name","company","phone","line1","line2","city","state","postcode","country"].forEach(function(k){if(form.elements[k])form.elements[k].value=a[k]||(k==="country"?"CH":"");});if(country._countryRefresh)country._countryRefresh();validate();}
      CheckoutAddressEditor.mount({form:form,addresses:addresses,onChange:validate,save:function(id,address){return sendJSON('POST',API+'/customer-auth/addresses',address);}});
      form.addEventListener("input",validate);form.addEventListener("change",validate);validate();
      document.getElementById('apply-coupon').onclick=requestQuote;form.elements.coupon_code.addEventListener('input',function(){quoteReady=false;document.getElementById('place-order').hidden=true;});
      form.addEventListener("submit",function(event){event.preventDefault();if(!form.checkValidity()){form.reportValidity();return;}var selected=form.querySelector('input[name="payment_method"]:checked'),shipping=form.querySelector('input[name="shipping_method"]:checked');if(!selected||!shipping||!quoteReady)return;var place=document.getElementById("place-order"),message=document.getElementById("checkout-message");message.textContent="Creating your secure payment…";place.disabled=true;var address={};["name","company","phone","line1","line2","city","state","postcode","country"].forEach(function(k){address[k]=form.elements[k].value.trim();});var requestKey=sessionStorage.getItem("ft_checkout_key");if(!requestKey){requestKey=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():(Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2));sessionStorage.setItem("ft_checkout_key",requestKey);}postJSON(API+"/checkout/place",{idempotency_key:requestKey,address:address,payment_method:selected.value,shipping_method:shipping.value,coupon_code:form.elements.coupon_code.value.trim(),items:state.cart.map(function(i){return{id:i.id,qty:i.qty};}),quote_currency:quotedCurrency,quote_total:quotedTotal,return_base:location.origin+BASE.replace(/\/$/,"")}).then(function(result){if(result.instructions)sessionStorage.setItem('ft_order_instructions',JSON.stringify({order_no:result.order_no||'',text:result.instructions}));else sessionStorage.removeItem('ft_order_instructions');if(result.clear_cart){state.cart=[];saveCart();renderCartHeader();renderDrawer();}location.href=result.redirect;}).catch(function(error){message.textContent=error.message;message.classList.add("is-error");place.disabled=false;if(/prices|exchange rates|latest checkout total/i.test(error.message))requestQuote();});});
    }).catch(function(error){if(/log in/i.test(error.message)){pushUrl(U("account"));openLogin("login");return;}app.innerHTML='<div class="container checkout-result checkout-result--error"><h1>Checkout unavailable</h1><p>'+esc(error.message)+'</p><a class="btn" href="'+U("cart")+'">Return to cart</a></div>'+footerHtml();});
  }

  /* ---------------- ACCOUNT / LOGIN ---------------- */
  function renderAuthPage(tab) {
    if (state.customer) { renderAccount(); return; }
    app.innerHTML = '<div class="container">' + breadcrumb([{label:"Account",href:U("account")},{label:tab === "register" ? "Register" : "Log in"}]) +
      '<div class="account-gate"><div class="auth-lock">&#128274;</div><h1>' + (tab === "register" ? "Create a business account" : "Welcome back") + '</h1><p>' + (tab === "register" ? "Register your company to request role-based wholesale pricing." : "Log in to view your approved prices and place orders.") + '</p><button class="btn btn-primary" id="open-auth-page">' + (tab === "register" ? "Open registration form" : "Open login form") + '</button></div></div>' + footerHtml();
    document.getElementById("open-auth-page").addEventListener("click", function () { openLogin(tab); });
    setTimeout(function () { openLogin(tab); }, 0);
  }
  function renderAccount() {
    var accountQuery=parseRoute().query;if(accountQuery.payment==='success'&&state.cart.length){state.cart=[];saveCart();renderCartHeader();renderDrawer();}var fragment=location.hash.match(/^#reset_token=([a-f0-9]{64})$/i);if(fragment){accountQuery.reset_token=fragment[1];history.replaceState({},'',location.pathname+location.search);}if(accountQuery.reset_token){if(location.search.indexOf('reset_token=')>=0)history.replaceState({},'',location.pathname);app.innerHTML='<div class="container">'+breadcrumb([{label:"Account",href:U("account")},{label:"Reset password"}])+'<div class="account-gate"><div class="auth-lock">&#128274;</div><h1>Choose a new password</h1><p>Use at least 12 characters with uppercase, lowercase, a number, and a special character.</p><form class="acct-form" id="reset-password-form"><label>New password<input type="password" name="password" minlength="12" required></label><label>Confirm password<input type="password" name="password_confirm" minlength="12" required></label><button class="btn btn-primary">Update password</button><p id="reset-password-message"></p></form></div></div>'+footerHtml();document.getElementById('reset-password-form').onsubmit=function(e){e.preventDefault();var f=e.target,m=document.getElementById('reset-password-message');postJSON(API+'/customer-auth/reset-password',{token:accountQuery.reset_token,password:f.password.value,password_confirm:f.password_confirm.value}).then(function(r){m.textContent=r.message;setTimeout(function(){pushUrl(U('account'));},1200);}).catch(function(err){m.textContent=err.message;});};return;}
    if (state.customer && !state.b2b) {
      app.innerHTML = '<div class="container">' + breadcrumb([{label:"Account",href:U("account")},{label:"Approval pending"}]) +
        '<div class="account-gate account-pending"><div class="auth-lock">&#9203;</div><span class="status-pill">Verification pending</span><h1>Thanks, ' + esc(state.customer.first_name || state.customer.username || "customer") + '</h1>' +
        '<p>Your business registration is saved. An administrator must verify your company and activate the <strong>' + esc(String(state.customer.requested_group || "retail").replace(/_/g," ")) + '</strong> pricing role before prices and ordering become available.</p>' +
        '<div class="pending-details"><span>Company<strong>' + esc(state.customer.company || "—") + '</strong></span><span>VAT<strong>' + esc(state.customer.vat_id || "—") + '</strong></span><span>Account email<strong>' + esc(state.customer.email) + '</strong></span></div>' +
        '<button class="btn" id="pending-logout">Log out</button></div></div>' + footerHtml();
      document.getElementById("pending-logout").addEventListener("click", function () { logoutCustomer(); });
      return;
    }
    if (state.b2b) {
      app.innerHTML = '<div class="container ferry-main-account">' + breadcrumb([{ label: "Account", href: U("account") }, { label: "Dashboard" }]) + '<div class="myaccount">' +
        '<nav class="myaccount-nav"><ul>' +
          '<li class="is-active" data-pane="dashboard"><a href="#">Dashboard</a></li>' +
          '<li data-pane="orders"><a href="#">Orders</a></li>' +
          '<li data-pane="returns"><a href="#">Returns</a></li>' +
          '<li data-pane="bulk-order"><a href="#">Bulk order</a></li>' +
          '<li data-pane="saved-lists"><a href="#">Saved lists</a></li>' +
          '<li data-pane="downloads"><a href="#">Downloads</a></li>' +
          '<li data-pane="addresses"><a href="#">Addresses</a></li>' +
          '<li data-pane="details"><a href="#">Account details</a></li>' +
          '<li><a href="#" id="acct-logout">Log out</a></li>' +
        '</ul></nav>' +
        '<div class="myaccount-content">' +
          '<div class="acct-pane" id="pane-dashboard">' +
            '<p class="acct-greeting">Hello <strong>' + esc((state.customer && state.customer.email) || "customer") + '</strong> (not you? <a href="#" id="acct-logout2">Log out</a>)</p>' +
            '<p>From your account dashboard you can view your recent orders, manage your shipping and billing addresses, and edit your password and account details.</p>' +
            '<div class="acct-cards">' +
              '<a class="acct-card" href="#" data-pane="orders"><b>0</b><span>Orders</span></a>' +
              '<a class="acct-card" href="#" data-pane="downloads"><b>0</b><span>Downloads</span></a>' +
              '<a class="acct-card" href="#" data-pane="addresses"><b>0</b><span>Addresses</span></a>' +
            '</div>' +
          '</div>' +
          '<div class="acct-pane" id="pane-orders" style="display:none"><h2>Orders</h2><div class="acct-orders-status"></div><div class="acct-empty">Loading orders…</div></div>' +
          '<div class="acct-pane" id="pane-returns" style="display:none"><h2>Returns</h2><div class="acct-empty">Loading returns…</div></div>' +
          '<div class="acct-pane" id="pane-bulk-order" style="display:none"><h2>Bulk order by SKU</h2><p>Paste one SKU and quantity per line, for example <code>ABC-123, 5</code>.</p><textarea id="bulk-order-input" class="bulk-order-input" rows="10" placeholder="SKU, quantity"></textarea><div class="acct-address-actions"><button type="button" class="btn btn-primary" id="resolve-bulk-order">Check products</button></div><div id="bulk-order-result"></div></div>' +
          '<div class="acct-pane" id="pane-saved-lists" style="display:none"><h2>Saved order lists</h2><div class="acct-empty">Loading lists…</div></div>' +
          '<div class="acct-pane" id="pane-downloads" style="display:none"><h2>Downloads</h2><div class="acct-empty">No downloads available.</div></div>' +
          '<div class="acct-pane" id="pane-addresses" style="display:none"><h2>Addresses</h2><div class="acct-empty">Loading addresses…</div></div>' +
          '<div class="acct-pane" id="pane-details" style="display:none"><h2>Account details</h2>' +
            '<form class="acct-form" id="acct-details-form">' +
              '<label>Email address</label><input type="email" value="' + esc((state.customer && state.customer.email) || "") + '" readonly>' +
              '<label>First name</label><input type="text" name="first_name" autocomplete="given-name" value="' + esc((state.customer && state.customer.first_name) || "") + '">' +
              '<label>Last name</label><input type="text" name="last_name" autocomplete="family-name" value="' + esc((state.customer && state.customer.last_name) || "") + '">' +
              '<label>Phone</label><input type="tel" name="phone" autocomplete="tel" value="' + esc((state.customer && state.customer.phone) || "") + '">' +
              '<h3>Change password <small>(optional)</small></h3>' +
              '<label>Current password</label><input type="password" name="current_password" autocomplete="current-password">' +
              '<label>New password</label><input type="password" name="password" autocomplete="new-password" minlength="12">' +
              '<label>Confirm new password</label><input type="password" name="password_confirm" autocomplete="new-password" minlength="12">' +
              '<button type="submit" class="btn btn-primary">Save changes</button>' +
            '</form>' +
            '<p class="account-note" id="details-saved" style="display:none;color:var(--primary)">Account details saved.</p>' +
            '<div class="account-privacy"><h3>Privacy controls</h3><p>Download your account data or submit a reviewed deletion request.</p><button type="button" class="btn" id="export-account-data">Download my data</button><label>Current password<input type="password" id="deletion-password" autocomplete="current-password"></label><button type="button" class="btn" id="request-account-deletion">Request account deletion</button><p id="privacy-message"></p></div>'+
          '</div>' +
        '</div>' +
      '</div></div>' + footerHtml();

      var ordersLoaded = false, returnsLoaded = false;
      var addressesLoaded = false, listsLoaded=false;
      function addResolvedToCart(items){items.filter(function(x){return x.available;}).forEach(function(x){var found=state.cart.find(function(i){return +i.id===+x.id;});if(found)found.qty=Math.max(found.qty,+x.qty);else state.cart.push({id:+x.id,sku:x.sku,name:x.name,slug:x.slug,image:imgUrl(x.image),qty:+x.qty,price:null});});saveCart();renderCartHeader();renderDrawer();}
      function loadSavedLists(){if(listsLoaded)return;listsLoaded=true;getJSON(API+'/b2b/lists').then(function(d){var pane=document.getElementById('pane-saved-lists'),lists=d.items||[];pane.innerHTML='<div class="acct-address-head"><div><h2>Saved order lists</h2><p>Keep frequently ordered products ready for the next purchase.</p></div></div><form id="create-saved-list" class="saved-list-create"><input name="name" maxlength="120" required placeholder="New list name"><button class="btn">Create list</button></form><div class="saved-list-grid">'+(lists.map(function(l){return '<article class="saved-list-card" data-list="'+l.id+'"><header><div><b>'+esc(l.name)+'</b><small>'+l.item_count+' products · '+l.unit_count+' units</small></div><button type="button" class="saved-list-delete">Remove</button></header><div>'+((l.items||[]).map(function(i){return '<p><span>'+esc(i.sku)+' — '+esc(i.name)+'</span><b>× '+i.qty+'</b></p>';}).join('')||'<em>No products yet.</em>')+'</div>'+(l.items&&l.items.length?'<button type="button" class="btn saved-list-cart">Add available products to cart</button>':'')+'</article>';}).join('')||'<div class="acct-empty">No saved lists yet.</div>')+'</div>';pane.querySelector('#create-saved-list').onsubmit=function(e){e.preventDefault();postJSON(API+'/b2b/lists',{name:e.target.name.value.trim()}).then(function(){listsLoaded=false;loadSavedLists();}).catch(function(x){alert(x.message);});};pane.querySelectorAll('.saved-list-delete').forEach(function(b){b.onclick=function(){var card=b.closest('[data-list]');sendJSON('DELETE',API+'/b2b/lists/'+card.dataset.list,{}).then(function(){card.remove();});};});pane.querySelectorAll('.saved-list-cart').forEach(function(b){b.onclick=function(){var id=+b.closest('[data-list]').dataset.list,list=lists.find(function(x){return +x.id===id;});addResolvedToCart((list.items||[]).map(function(x){x.available=x.stock_status==='instock'&&(!+x.manage_stock||+x.stock_qty>=+x.qty);return x;}));toastCartMessage('Available list products added to cart');};});}).catch(function(e){document.getElementById('pane-saved-lists').innerHTML='<div class="acct-empty">'+esc(e.message)+'</div>';});}
      function initBulkOrder(){var button=document.getElementById('resolve-bulk-order');if(!button||button.dataset.ready)return;button.dataset.ready='1';button.onclick=function(){var rows=document.getElementById('bulk-order-input').value.split(/\r?\n/).filter(Boolean).map(function(line){var p=line.split(/[;,\t]/);return{sku:(p[0]||'').trim(),qty:Math.max(1,parseInt(p[1]||'1',10)||1)};});var out=document.getElementById('bulk-order-result');out.innerHTML='<div class="acct-empty">Checking products…</div>';postJSON(API+'/b2b/bulk-resolve',{items:rows}).then(function(d){out.innerHTML='<div class="bulk-result">'+d.items.map(function(x){return '<p class="'+(x.available?'':'is-unavailable')+'"><span><b>'+esc(x.sku)+'</b> '+esc(x.name)+'</span><strong>'+x.qty+' units · '+(x.available?'Available':'Insufficient stock')+'</strong></p>';}).join('')+'</div>'+(d.errors.length?'<p class="account-note">'+d.errors.map(function(x){return 'Row '+x.row+': '+esc(x.sku)+' not found';}).join('<br>')+'</p>':'')+'<button type="button" class="btn btn-primary" id="add-bulk-cart">Add available products to cart</button>';document.getElementById('add-bulk-cart').onclick=function(){addResolvedToCart(d.items);toastCartMessage('Bulk products added to cart');};}).catch(function(e){out.innerHTML='<div class="acct-empty">'+esc(e.message)+'</div>';});};}
      function loadCustomerAddresses(){if(addressesLoaded)return;addressesLoaded=true;getJSON(API+'/customer-auth/addresses').then(function(result){var pane=document.getElementById('pane-addresses'),items=result.items||[];function card(a){return '<article class="acct-address-card" data-address-id="'+a.id+'"><div><b>'+esc(a.name)+'</b><span>'+esc(a.type)+'</span><p>'+esc(a.company||'')+'<br>'+esc(a.line1)+(a.line2?'<br>'+esc(a.line2):'')+'<br>'+esc(a.postcode)+' '+esc(a.city)+', '+esc(marketCountries[a.country]||a.country)+'<br>'+esc(a.phone)+'</p></div><button type="button" class="acct-address-delete">Remove</button></article>';}pane.innerHTML='<div class="acct-address-head"><div><h2>Addresses</h2><p>Saved addresses make checkout faster. Exact duplicates are blocked.</p></div><button type="button" class="btn" id="add-address">Add address</button></div><div class="acct-address-grid">'+(items.map(card).join('')||'<div class="acct-empty">No addresses saved.</div>')+'</div><form class="acct-address-form" id="address-form" hidden><h3>Add address</h3><div class="checkout-fields">'+[['name','Full name'],['company','Company'],['phone','Phone'],['line1','Address line 1'],['line2','Address line 2'],['city','City'],['state','State / Canton'],['postcode','Postcode']].map(function(x){return '<label class="checkout-field"><span>'+x[1]+'</span><input name="'+x[0]+'" '+(['name','phone','line1','city','postcode'].indexOf(x[0])>=0?'required':'')+'></label>';}).join('')+'<label class="checkout-field"><span>Country</span><select name="country" required autocomplete="country">'+countryOptions('CH')+'</select></label></div><div class="acct-address-actions"><button type="button" class="btn" id="cancel-address">Cancel</button><button class="btn btn-primary">Save address</button></div><p id="address-message"></p></form>';enhanceCountrySelect(pane.querySelector('[name=country]'));pane.querySelector('#add-address').onclick=function(){pane.querySelector('#address-form').hidden=false;};pane.querySelector('#cancel-address').onclick=function(){pane.querySelector('#address-form').hidden=true;};pane.querySelectorAll('.acct-address-delete').forEach(function(button){button.onclick=function(){var article=button.closest('[data-address-id]');sendJSON('DELETE',API+'/customer-auth/addresses/'+article.dataset.addressId,{}).then(function(){article.remove();}).catch(function(e){alert(e.message);});};});pane.querySelector('#address-form').onsubmit=function(e){e.preventDefault();var body={type:'shipping'};new FormData(e.target).forEach(function(v,k){body[k]=String(v).trim();});sendJSON('POST',API+'/customer-auth/addresses',body).then(function(){addressesLoaded=false;loadCustomerAddresses();}).catch(function(err){pane.querySelector('#address-message').textContent=err.message;});};}).catch(function(error){var pane=document.getElementById('pane-addresses');if(pane)pane.innerHTML='<h2>Addresses</h2><div class="acct-empty">'+esc(error.message)+'</div>';});}
      function loadCustomerOrders() {
        if (ordersLoaded) return; ordersLoaded = true;
        getJSON(API + "/checkout/orders").then(function (result) {
          var pane = document.getElementById("pane-orders"), items = result.items || [];
          if (!pane) return;
          var success = '';
          if(parseRoute().query.payment === "success"){
            var offline=null;try{offline=JSON.parse(sessionStorage.getItem('ft_order_instructions')||'null');}catch(ignore){}
            success='<div class="acct-order-success"><b>Order placed successfully.</b>'+(offline&&offline.order_no?'<span>Order '+esc(offline.order_no)+'</span>':'')+(offline&&offline.text?'<p>'+esc(offline.text).replace(/\n/g,'<br>')+'</p>':'<p>Payment confirmation and order updates will appear here.</p>')+'</div>';
            sessionStorage.removeItem('ft_order_instructions');
          }
          pane.innerHTML = '<h2>Orders</h2>' + success + (items.length ? '<div class="acct-orders">' + items.map(function (order) { var tracking=order.tracking_number?'<span class="acct-order-tracking">'+esc(order.tracking_carrier||'Shipment')+': '+(order.tracking_url?'<a href="'+esc(order.tracking_url)+'" target="_blank" rel="noopener noreferrer">'+esc(order.tracking_number)+' ↗</a>':'<b>'+esc(order.tracking_number)+'</b>')+' · '+esc(String(order.tracking_status||'').replace(/_/g,' '))+'</span>':'';return '<article><div><strong>' + esc(order.order_no) + '</strong><span>' + esc(order.created_at || "") + '</span>'+tracking+'</div><span class="acct-order-status">' + esc(String(order.status || "pending").replace(/_/g," ")) + '</span><b>' + money(order.total,order.currency) + '</b><button type="button" class="btn acct-reorder" data-order="'+order.id+'">Reorder</button></article>'; }).join("") + '</div>' : '<div class="acct-empty">No orders yet.</div>');pane.querySelectorAll('.acct-reorder').forEach(function(b){b.onclick=function(){getJSON(API+'/b2b/reorder/'+b.dataset.order).then(function(d){addResolvedToCart(d.items);toastCartMessage('Available products added from this order');});};});
        }).catch(function (error) { var pane=document.getElementById("pane-orders"); if(pane)pane.innerHTML='<h2>Orders</h2><div class="acct-empty">'+esc(error.message)+'</div>'; });
      }
      function loadCustomerReturns(){
        if(returnsLoaded)return;returnsLoaded=true;getJSON(API+'/returns').then(function(data){var pane=document.getElementById('pane-returns'),requests=data.items||[],eligible=data.eligible_items||[],groups={};eligible.forEach(function(i){(groups[i.id]||(groups[i.id]={id:i.id,order_no:i.order_no,created_at:i.created_at,items:[]})).items.push(i);});var history=requests.length?'<div class="return-history">'+requests.map(function(r){return '<article data-return="'+r.id+'"><div><b>'+esc(r.rma_no)+'</b><span>Order '+esc(r.order_no)+' · '+esc(r.requested_at)+'</span></div><span class="acct-order-status">'+esc(r.status)+'</span><strong>'+esc(r.item_qty)+' item(s)</strong>'+(r.status==='requested'?'<button type="button" class="btn return-cancel">Cancel</button>':'')+(r.customer_message?'<p>'+esc(r.customer_message)+'</p>':'')+'</article>';}).join('')+'</div>':'<div class="acct-empty">No return requests yet.</div>';var forms=Object.values(groups).map(function(o){return '<form class="return-request-form" data-order="'+o.id+'"><header><div><b>Order '+esc(o.order_no)+'</b><span>'+esc(o.created_at)+' · eligible for '+esc(data.return_window_days)+' days</span></div><button type="button" class="return-form-toggle">Request return</button></header><div class="return-request-fields" hidden><div class="return-product-list">'+o.items.map(function(i){var available=+i.qty-+i.returned_qty;return '<label><input type="checkbox" value="'+i.order_item_id+'" data-max="'+available+'"><span><b>'+esc(i.name)+'</b><small>SKU '+esc(i.sku)+' · '+available+' eligible</small></span><input class="return-qty" type="number" min="1" max="'+available+'" value="1" aria-label="Return quantity"><select class="return-condition"><option value="defective">Defective</option><option value="damaged">Damaged</option><option value="wrong_item">Wrong item</option><option value="unopened">Unopened</option><option value="opened">Opened</option><option value="other">Other</option></select></label>';}).join('')+'</div><label>Reason<select name="reason" required><option value="">Choose a reason</option><option>Defective product</option><option>Damaged in transit</option><option>Wrong item received</option><option>Ordered by mistake</option><option>Other</option></select></label><label>Details<textarea name="customer_note" rows="3" placeholder="Tell us what happened"></textarea></label><button class="btn" type="submit">Submit return request</button><p class="return-form-message"></p></div></form>';}).join('');pane.innerHTML='<div class="acct-address-head"><div><h2>Returns</h2><p>Track existing requests or return eligible purchased items.</p></div></div><h3>My requests</h3>'+history+'<h3>Eligible orders</h3>'+(forms||'<div class="acct-empty">No shipped orders are currently inside the return window.</div>');pane.querySelectorAll('.return-form-toggle').forEach(function(b){b.onclick=function(){var f=b.closest('form').querySelector('.return-request-fields');f.hidden=!f.hidden;};});pane.querySelectorAll('.return-request-form').forEach(function(form){form.onsubmit=function(e){e.preventDefault();var items=[];form.querySelectorAll('.return-product-list>label').forEach(function(row){var check=row.querySelector('input[type=checkbox]');if(check.checked)items.push({order_item_id:+check.value,qty:+row.querySelector('.return-qty').value,condition:row.querySelector('.return-condition').value});});var message=form.querySelector('.return-form-message');if(!items.length){message.textContent='Select at least one product.';return;}postJSON(API+'/returns',{order_id:+form.dataset.order,reason:form.reason.value,customer_note:form.customer_note.value,items:items}).then(function(r){message.textContent='Return '+r.rma_no+' submitted.';returnsLoaded=false;loadCustomerReturns();}).catch(function(x){message.textContent=x.message;});};});pane.querySelectorAll('.return-cancel').forEach(function(b){b.onclick=function(){var row=b.closest('[data-return]');postJSON(API+'/returns/'+row.dataset.return+'/cancel',{}).then(function(){returnsLoaded=false;loadCustomerReturns();}).catch(function(x){alert(x.message);});};});}).catch(function(e){var pane=document.getElementById('pane-returns');if(pane)pane.innerHTML='<h2>Returns</h2><div class="acct-empty">'+esc(e.message)+'</div>';});
      }
      function switchPane(name) {
        app.querySelectorAll(".myaccount-nav li").forEach(function (li) { li.classList.remove("is-active"); });
        var navLi = app.querySelector('.myaccount-nav li[data-pane="' + name + '"]');
        if (navLi) navLi.classList.add("is-active");
        app.querySelectorAll(".acct-pane").forEach(function (p) { p.style.display = "none"; });
        var pane = document.getElementById("pane-" + name);
        if (pane) pane.style.display = "block";
        if (name === "orders") loadCustomerOrders();
        if (name === "returns") loadCustomerReturns();
        if (name === "addresses") loadCustomerAddresses();
        if (name === "saved-lists") loadSavedLists();
        if (name === "bulk-order") initBulkOrder();
      }
      app.querySelectorAll("[data-pane]").forEach(function (el) {
        el.addEventListener("click", function (e) { e.preventDefault(); switchPane(el.getAttribute("data-pane")); });
      });
      var doLogout = function (e) {
        if (e) e.preventDefault();
        logoutCustomer(function () { renderAccount(); });
      };
      var lo = document.getElementById("acct-logout"); if (lo) lo.addEventListener("click", doLogout);
      var lo2 = document.getElementById("acct-logout2"); if (lo2) lo2.addEventListener("click", doLogout);
      var df = document.getElementById("acct-details-form"); if (df) df.addEventListener("submit", function (e) { e.preventDefault();var changingPassword=!!df.password.value,body={first_name:df.first_name.value.trim(),last_name:df.last_name.value.trim(),phone:df.phone.value.trim()};if(changingPassword){body.current_password=df.current_password.value;body.password=df.password.value;body.password_confirm=df.password_confirm.value;}sendJSON('PUT',API+'/customer-auth/profile',body).then(function(){var note=document.getElementById("details-saved");note.textContent=changingPassword?"Profile and password updated. Other signed-in devices were logged out.":"Profile updated.";note.style.display="block";if(changingPassword){df.current_password.value='';df.password.value='';df.password_confirm.value='';}if(state.customer){state.customer.first_name=body.first_name;state.customer.last_name=body.last_name;state.customer.phone=body.phone;}}).catch(function(error){var note=document.getElementById("details-saved");note.textContent=error.message;note.style.display="block";}); });
      var exportButton=document.getElementById('export-account-data');if(exportButton)exportButton.onclick=function(){getJSON(API+'/customer-auth/export').then(function(data){var blob=new Blob([JSON.stringify(data,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='ferry-account-data.json';a.click();setTimeout(function(){URL.revokeObjectURL(url);},1000);});};var deletionButton=document.getElementById('request-account-deletion');if(deletionButton)deletionButton.onclick=function(){var message=document.getElementById('privacy-message'),password=document.getElementById('deletion-password').value;postJSON(API+'/customer-auth/deletion-request',{password:password}).then(function(r){message.textContent=r.message;}).catch(function(e){message.textContent=e.message;});};
      var requestedPane = parseRoute().query.pane;
      if (["orders","returns","bulk-order","saved-lists","downloads","addresses","details"].indexOf(requestedPane) >= 0) switchPane(requestedPane);
      return;
    }
    app.innerHTML = '<div class="container">' + breadcrumb([{ label: "Account", href: U("account") }, { label: "Login / Register" }]) +
      '<div class="account-gate"><div class="auth-lock">&#128274;</div><h1>Wholesale account access</h1><p>Log in to see pricing and place orders, or create a new business account.</p><button class="btn btn-primary" id="open-account-auth">Log in or register</button></div></div>' + footerHtml();
    document.getElementById("open-account-auth").addEventListener("click", function () { openLogin("login"); });
    setTimeout(function () { openLogin("login"); }, 0);
  }
  function setAuthMessage(text, error) {
    var el = document.getElementById("auth-message");
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("is-error", !!error);
  }
  function switchAuthTab(name) {
    name = name === "register" ? "register" : "login";
    document.querySelectorAll("[data-auth-tab]").forEach(function (tab) {
      var active = tab.getAttribute("data-auth-tab") === name;
      tab.classList.toggle("active", active); tab.setAttribute("aria-selected", active ? "true" : "false");
    });
    document.querySelectorAll("[data-auth-panel]").forEach(function (panel) {
      var active = panel.getAttribute("data-auth-panel") === name;
      panel.classList.toggle("active", active); panel.hidden = !active;
    });
    setAuthMessage("");
  }
  function doLogin(email, password) {
    email = email || (document.getElementById("login-email") && document.getElementById("login-email").value) || (document.getElementById("acct-email") && document.getElementById("acct-email").value) || "";
    password = password || (document.getElementById("login-pass") && document.getElementById("login-pass").value) || (document.getElementById("acct-pass") && document.getElementById("acct-pass").value) || "";
    setAuthMessage("Signing in…");
    return postJSON(API + "/customer-auth/login", { identity:email, password:password }).then(function (data) {
      applyCustomerSession(data); closeLogin(); refreshCartPrices(); renderCartHeader(); renderDrawer(); route(); return true;
    }).catch(function (error) { setAuthMessage(error.message || "Unable to sign in.", true); return false; });
  }
  function doRegister() {
    var data = {
      first_name:document.getElementById("reg-first").value.trim(), last_name:document.getElementById("reg-last").value.trim(),
      phone:document.getElementById("reg-phone").value.trim(), email:document.getElementById("reg-email").value.trim(), username:document.getElementById("reg-username").value.trim(),
      company:document.getElementById("reg-company").value.trim(), vat_id:document.getElementById("reg-vat").value.trim(), eori_number:document.getElementById("reg-eori").value.trim(),
      business_type:document.getElementById("reg-business").value, country:document.getElementById("reg-country").value,
      line1:document.getElementById("reg-line1").value.trim(), line2:document.getElementById("reg-line2").value.trim(), city:document.getElementById("reg-city").value.trim(), state:document.getElementById("reg-state").value.trim(), postcode:document.getElementById("reg-postcode").value.trim(),
      password:document.getElementById("reg-password").value, password_confirm:document.getElementById("reg-confirm").value
    };
    if (data.password !== data.password_confirm) { setAuthMessage("The passwords do not match.", true); return; }
    setAuthMessage("Submitting your registration…");
    postJSON(API + "/customer-auth/register", data).then(function (result) {
      document.getElementById("register-form").reset(); switchAuthTab("login");
      document.getElementById("login-email").value = data.email;
      setAuthMessage(result.message || "Registration received. Please wait for approval.");
    }).catch(function (error) { setAuthMessage(error.message || "Registration failed.", true); });
  }
  function logoutCustomer(done) {
    postJSON(API + "/customer-auth/logout", {}).catch(function () {}).then(function () {
      applyCustomerSession(null); state.cart.forEach(function (i) { i.price = null; }); saveCart();
      renderCartHeader(); renderDrawer(); closeAllPanels(); if (done) done(); else route();
    });
  }
  var cartPriceRequest = null, cartPriceVersion = 0;
  function refreshCartPrices() {
    if (!state.b2b || !state.cart.length) return Promise.resolve();
    if (cartPriceRequest) return cartPriceRequest;
    var ids = state.cart.map(function (i) { return i.id; });
    var priceVersion = cartPriceVersion;
    cartPriceRequest = postJSON(API + "/products/cart-prices", { ids: ids }).then(function (data) {
      if (priceVersion !== cartPriceVersion) return;
      CUR = data.currency || CUR;
      var byId = {};
      (data.items || []).forEach(function (product) { byId[String(product.id)] = product; });
      state.cart.forEach(function (item) {
        var product = byId[String(item.id)];
        item._priceResolved = true;
        if (!product) return;
        item.price = numericPrice(product.price);
        item.currency = product.currency || item.currency || CUR;
        item.name = product.name || item.name;
        item.image = product.image || item.image;
        item.sku = product.sku || item.sku;
      });
      saveCart(); renderCartHeader(); renderDrawer();
      if (parseRoute().path === "cart") renderCart();
    }).catch(function () {
      state.cart.forEach(function (item) { item._priceResolved = true; });
      saveCart(); renderCartHeader(); renderDrawer();
      if (parseRoute().path === "cart") renderCart();
    }).finally(function () { cartPriceRequest = null; });
    return cartPriceRequest;
  }
  function openLogin(tab) {
    switchAuthTab(tab || "login");
    document.getElementById("login-modal").classList.add("open");
    setTimeout(function () { var el = document.getElementById(tab === "register" ? "reg-first" : "login-email"); if (el) el.focus(); }, 80);
  }
  function closeLogin() { document.getElementById("login-modal").classList.remove("open"); }
  function setupPasswordToggles() {
    Array.prototype.forEach.call(document.querySelectorAll('#login-modal input[type="password"]'), function (input) {
      if (input.parentNode.classList && input.parentNode.classList.contains("password-control")) return;
      var wrap = document.createElement("span"); wrap.className = "password-control";
      input.parentNode.insertBefore(wrap, input); wrap.appendChild(input);
      var button = document.createElement("button"); button.type = "button"; button.className = "password-toggle";
      button.setAttribute("aria-label", "Show password"); button.setAttribute("aria-pressed", "false");
      button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>';
      button.addEventListener("click", function () {
        var show = input.type === "password"; input.type = show ? "text" : "password";
        button.classList.toggle("is-visible", show); button.setAttribute("aria-pressed", show ? "true" : "false");
        button.setAttribute("aria-label", show ? "Hide password" : "Show password"); input.focus();
      });
      wrap.appendChild(button);
    });
  }

  /* ---------------- INIT ---------------- */
  function init() {
    if (THEME === "template-6" && window.FerryMain) FerryMain.mountHeader({
      base:BASE, url:U, get:function(path){return getJSON(API+path);},
      catMedia:catMedia, money:money, placeholder:PLACEHOLDER, add:addToCart,inCart:function(id){return state.cart.some(function(item){return +item.id===+id;});},
      approved:function(){return state.b2b;}, notice:toastCartMessage,
      searchUrl:searchPath,
      search:function(q){pendingShopInit=null;pushUrl(searchPath(q));}
    });
    var yrEl = document.getElementById("year"); if (yrEl) yrEl.textContent = new Date().getFullYear();
    buildNav();
    loadMarketCountries().catch(function(){});
    if (THEME !== "template-6") setupSearch();
    renderCartHeader();
    renderDrawer();

    // Intercept internal links; resolve relative hrefs against <base>.
    document.addEventListener("click", function (e) {
      if (e.defaultPrevented) return;
      var a = e.target.closest("a");
      if (!a) return;
      if (a.target === "_blank") return;
      var href = a.getAttribute("href");
      if (!href) return;
      var abs = new URL(href, document.baseURI);
      if (abs.origin !== location.origin) return;
      if (abs.pathname === location.pathname && abs.search === location.search) { e.preventDefault(); return; }
      e.preventDefault();
      closeAllPanels();
      pushUrl(abs.pathname + abs.search);
    });

    document.getElementById("cart-drawer-close").addEventListener("click", closeDrawer);
    document.getElementById("overlay").addEventListener("click", closeDrawer);
    document.getElementById("menu-extra-register").addEventListener("click", function (e) {
      e.preventDefault();
      if (state.customer) togglePanel("account-menu"); else openLogin();
    });
    function doLogout(e) {
      if (e) e.preventDefault();
      logoutCustomer();
    }
    var accLogout = document.getElementById("account-logout");
    if (accLogout) accLogout.addEventListener("click", doLogout);
    var accLogout2 = document.getElementById("account-logout-2");
    if (accLogout2) accLogout2.addEventListener("click", doLogout);
    document.getElementById("icon-cart-contents").addEventListener("click", function (e) { e.preventDefault(); togglePanel("mini-cart"); });
    document.getElementById("icon-wishlist-contents").addEventListener("click", function (e) { e.preventDefault(); renderWishlist(); togglePanel("wishlist-panel"); });
    var c2 = document.getElementById("icon-cart-contents-2");
    if (c2) c2.addEventListener("click", function (e) { e.preventDefault(); togglePanel("mini-cart-2"); });
    var w2 = document.getElementById("icon-wishlist-contents-2");
    if (w2) w2.addEventListener("click", function (e) { e.preventDefault(); renderWishlistInto(document.getElementById("wishlist-panel-2")); togglePanel("wishlist-panel-2"); });
    var a2 = document.getElementById("menu-extra-register-2");
    if (a2) a2.addEventListener("click", function (e) { e.preventDefault(); if (state.customer) togglePanel("account-menu-2"); else openLogin(); });
    document.addEventListener("click", function (e) {
      if (e.target.closest("#mini-cart") || e.target.closest("#account-menu") || e.target.closest("#wishlist-panel") || e.target.closest("#mini-cart-2") || e.target.closest("#account-menu-2") || e.target.closest("#wishlist-panel-2")) return;
      if (e.target.closest(".menu-item-cart") || e.target.closest(".menu-item-account") || e.target.closest(".menu-item-wishlist") || e.target.closest(".nav-actions")) return;
      closeAllPanels();
    });
    updateWishlistCount();
    updateWishlistHearts();
    var navSentinel = document.querySelector(".nav-sticky-sentinel");
    var navWrap = document.getElementById("primaryNavWrap");
    var navSpacer = document.getElementById("navStickySpacer");
    if (navSentinel && navWrap && navSpacer && "IntersectionObserver" in window) {
      var navObserver = new IntersectionObserver(function (entries) {
        var stuck = !entries[0].isIntersecting;
        navWrap.classList.toggle("is-stuck", stuck);
        navSpacer.classList.toggle("is-active", stuck);
      }, { rootMargin: "-1px 0px 0px 0px", threshold: 0 });
      navObserver.observe(navSentinel);
    }
    document.getElementById("login-close").addEventListener("click", closeLogin);
    document.getElementById("login-modal").addEventListener("click", function (e) { if (e.target === this) closeLogin(); });
    document.querySelectorAll("[data-auth-tab]").forEach(function (tab) { tab.addEventListener("click", function () { switchAuthTab(tab.getAttribute("data-auth-tab")); }); });
    setupPasswordToggles();
    document.getElementById("login-form").addEventListener("submit", function (e) { e.preventDefault(); doLogin(); });
    document.getElementById("forgot-password").addEventListener("click",function(){var email=document.getElementById('login-email').value.trim();if(!email){setAuthMessage('Enter your email address first.',true);document.getElementById('login-email').focus();return;}setAuthMessage('Sending reset link…');postJSON(API+'/customer-auth/request-reset',{email:email}).then(function(r){setAuthMessage(r.message);}).catch(function(e){setAuthMessage(e.message,true);});});
    document.getElementById("register-form").addEventListener("submit", function (e) { e.preventDefault(); doRegister(); });

    window.addEventListener("popstate", route);
    loadPrimaryNavigation();
    initThemeSwitcher();
    getJSON(API + "/customer-auth/me").then(function (data) { applyCustomerSession(data); if(THEME==="template-6")FerryMain.updateAccount(state.customer); renderCartHeader(); renderDrawer(); refreshCartPrices(); route(); }).catch(function () { applyCustomerSession(null); route(); });
  }

  /* ---------- storefront theme switcher (Home > Templates) ---------- */
  function applyTheme(key) {
    var allowed = ["template-1", "template-2", "template-3", "template-4", "template-5", "template-6"];
    if (allowed.indexOf(key) < 0) return;
    if (key === "template-6" || THEME === "template-6") {
      var destination = new URL(location.href);
      destination.searchParams.set("theme", key);
      location.assign(destination.href);
      return;
    }
    THEME = key;
    window.__THEME__ = key;
    document.body.setAttribute("data-theme", key);
    var head = document.head;
    var link = document.getElementById("theme-stylesheet");
    if (key === "template-1") {
      if (link && link.parentNode) link.parentNode.removeChild(link);
    } else {
      if (!link) { link = document.createElement("link"); link.rel = "stylesheet"; link.id = "theme-stylesheet"; head.appendChild(link); }
      link.href = BASE + "assets/css/theme-" + key + ".css";
    }
    var ferryMainHome = document.getElementById("ferry-main-home-stylesheet");
    var ferryMainFrontend = document.getElementById("ferry-main-frontend-stylesheet");
    if (key === "template-6") {
      if (!ferryMainHome) { ferryMainHome = document.createElement("link"); ferryMainHome.rel = "stylesheet"; ferryMainHome.id = "ferry-main-home-stylesheet"; head.appendChild(ferryMainHome); }
      ferryMainHome.href = BASE + "assets/css/ferry-main-home.css";
      if (!ferryMainFrontend) { ferryMainFrontend = document.createElement("link"); ferryMainFrontend.rel = "stylesheet"; ferryMainFrontend.id = "ferry-main-frontend-stylesheet"; head.appendChild(ferryMainFrontend); }
      ferryMainFrontend.href = BASE + "assets/css/ferry-main-frontend.css";
    } else {
      if (ferryMainHome && ferryMainHome.parentNode) ferryMainHome.parentNode.removeChild(ferryMainHome);
      if (ferryMainFrontend && ferryMainFrontend.parentNode) ferryMainFrontend.parentNode.removeChild(ferryMainFrontend);
    }
    Array.prototype.forEach.call(document.querySelectorAll(".sub-menu a[data-theme-link]"), function (a) {
      a.classList.toggle("is-current", a.getAttribute("data-theme-link") === key);
    });
    var url = new URL(location.href);
    url.searchParams.set("theme", key);
    history.replaceState(null, "", url.pathname + url.search);
    var r = parseRoute();
    if (r.path === "" || r.path === "home") renderStorefrontHome();
  }

  function initThemeSwitcher() {
    var links = document.querySelectorAll(".sub-menu a[data-theme-link]");
    Array.prototype.forEach.call(links, function (a) {
      a.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        applyTheme(a.getAttribute("data-theme-link"));
      });
    });
    Array.prototype.forEach.call(links, function (a) {
      a.classList.toggle("is-current", a.getAttribute("data-theme-link") === THEME);
    });
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
