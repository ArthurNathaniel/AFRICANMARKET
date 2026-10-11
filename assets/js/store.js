/* Jenmond Mobile African Market - Storefront behaviour */
(function () {
  'use strict';

  // Generic toggles: <button data-toggle="#target">
  document.querySelectorAll('[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var t = document.querySelector(btn.getAttribute('data-toggle'));
      if (t) t.classList.toggle('open');
    });
  });

  // Mobile menu drawer: hamburger opens it; the search icon opens it on the search box.
  var drawer = document.getElementById('mDrawer'), lastFocus = null;
  function setDrawer(open, focusSearch) {
    if (!drawer) return;
    var menuBtn = document.querySelector('.menu-btn');
    if (open) {
      lastFocus = document.activeElement;
      drawer.hidden = false;
      void drawer.offsetWidth; // let the slide-in transition run
      drawer.classList.add('open');
      document.body.classList.add('drawer-open');
      (focusSearch ? drawer.querySelector('#mq') : drawer.querySelector('.m-drawer-x')).focus();
    } else {
      drawer.classList.remove('open');
      document.body.classList.remove('drawer-open');
      setTimeout(function () { if (!drawer.classList.contains('open')) drawer.hidden = true; }, 230);
      if (lastFocus) lastFocus.focus();
    }
    if (menuBtn) menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  document.querySelectorAll('[data-drawer-open]').forEach(function (b) {
    b.addEventListener('click', function () { setDrawer(true, b.getAttribute('data-drawer-open') === 'search'); });
  });
  if (drawer) {
    drawer.addEventListener('click', function (e) { if (e.target.closest('[data-drawer-close]')) setDrawer(false); });
    document.addEventListener('keydown', function (e) {
      if (!drawer.classList.contains('open')) return;
      if (e.key === 'Escape') setDrawer(false);
      if (e.key === 'Tab') { // keep keyboard focus inside the open menu
        var f = drawer.querySelectorAll('a, button, input'), first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    window.addEventListener('resize', function () { if (window.innerWidth > 900 && drawer.classList.contains('open')) setDrawer(false); });
  }

  document.querySelectorAll('.alert .close').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.alert').remove(); });
  });

  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  document.querySelectorAll('.pw-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = b.parentElement.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Quantity steppers: <div class="qty"><button data-step="-1">…<input>…<button data-step="1">
  document.querySelectorAll('.qty').forEach(function (box) {
    var input = box.querySelector('input');
    box.querySelectorAll('[data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        var max = parseInt(input.max || '99', 10), min = parseInt(input.min || '1', 10);
        var v = Math.min(max, Math.max(min, (parseInt(input.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10)));
        input.value = v;
        if (box.hasAttribute('data-autosubmit')) box.closest('form').submit();
      });
    });
    if (box.hasAttribute('data-autosubmit')) input.addEventListener('change', function () { box.closest('form').submit(); });
  });

  // Product gallery thumbnails
  document.querySelectorAll('.gallery-thumbs button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('mainImage').src = b.getAttribute('data-src');
      document.querySelectorAll('.gallery-thumbs button').forEach(function (x) { x.classList.remove('active'); });
      b.classList.add('active');
    });
  });

  // Toast: small confirmation at the bottom of the screen
  var toastBox = null, toastTimer = null;
  function toast(message, ok) {
    if (!toastBox) {
      toastBox = document.createElement('div');
      toastBox.className = 'toast';
      toastBox.setAttribute('role', 'status');
      document.body.appendChild(toastBox);
    }
    var cartUrl = (document.getElementById('cartLink') || {}).href || 'cart.php';
    toastBox.className = 'toast' + (ok ? '' : ' error');
    toastBox.innerHTML = '<i class="bi ' + (ok ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill') + '"></i><span></span>' + (ok ? '<a href="' + cartUrl + '">View cart</a>' : '');
    toastBox.querySelector('span').textContent = message;
    void toastBox.offsetWidth;
    toastBox.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastBox.classList.remove('show'); }, 3200);
  }

  function setCartCount(n) {
    document.querySelectorAll('[data-cart-badge]').forEach(function (b) {
      b.textContent = n || ''; b.hidden = !n;
      if (n) { b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
    });
    var link = document.getElementById('cartLink');
    if (!link) return;
    var badge = link.querySelector('.count');
    if (!n) { if (badge) badge.remove(); return; }
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'count';
      link.insertBefore(badge, link.querySelector('.label'));
    }
    badge.textContent = n;
    badge.classList.remove('bump'); void badge.offsetWidth; badge.classList.add('bump');
  }

  // Product cards: add to cart / quantity stepper without leaving the page
  function paintCard(box, qty, max) {
    box.classList.toggle('in-cart', qty > 0);
    box.querySelector('.n b').textContent = qty;
    var minus = box.querySelector('.step.minus'), plus = box.querySelector('.step.plus');
    minus.value = Math.max(0, qty - 1);
    minus.innerHTML = '<i class="bi ' + (qty <= 1 ? 'bi-trash3' : 'bi-dash-lg') + '"></i>';
    plus.value = qty + 1;
    plus.disabled = qty >= max;
  }
  // Fly-to-cart: a copy of the product photo arcs into the visible cart icon, which then bounces.
  function cartTarget() {
    var tab = document.querySelector('.tabbar a[href*="cart.php"]'), head = document.getElementById('cartLink');
    if (tab && tab.offsetParent) return tab;          // phones: bottom tab bar
    if (head && head.offsetParent) return head;       // desktop: header cart
    return null;
  }
  function popCart(target) {
    if (!target) return;
    target.classList.remove('cart-pop'); void target.offsetWidth; target.classList.add('cart-pop');
  }
  function flyToCart(img, onLand) {
    var target = cartTarget(), land = function () { popCart(target); if (onLand) onLand(); };
    var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!target || !img || calm || !Element.prototype.animate) { land(); return; }
    var s = img.getBoundingClientRect(), t = (target.querySelector('i') || target).getBoundingClientRect();
    if (!s.width || s.bottom < 0 || s.top > window.innerHeight) { land(); return; } // photo off-screen: just bounce
    var size = Math.min(s.width, s.height, 150);
    var ghost = document.createElement('img');
    ghost.src = img.currentSrc || img.src; ghost.alt = ''; ghost.className = 'fly-ghost';
    ghost.style.cssText = 'left:' + (s.left + s.width / 2 - size / 2) + 'px;top:' + (s.top + s.height / 2 - size / 2) + 'px;width:' + size + 'px;height:' + size + 'px';
    document.body.appendChild(ghost);
    var dx = t.left + t.width / 2 - (s.left + s.width / 2), dy = t.top + t.height / 2 - (s.top + s.height / 2);
    var anim = ghost.animate([
      { transform: 'translate(0, 0) scale(1) rotate(0deg)', opacity: 1, offset: 0 },
      { transform: 'translate(' + dx * 0.35 + 'px, ' + (Math.min(dy, 0) * 0.35 - 90) + 'px) scale(.7) rotate(-10deg)', opacity: 1, offset: 0.4 },
      { transform: 'translate(' + dx + 'px, ' + dy + 'px) scale(.12) rotate(12deg)', opacity: 0.4, offset: 1 }
    ], { duration: 780, easing: 'cubic-bezier(.45, 0, .55, 1)' });
    var finished = false, end = function () { if (finished) return; finished = true; ghost.remove(); land(); };
    anim.onfinish = end;
    setTimeout(end, 1200); // in case the animation never reports finishing
  }
  function cartNow() {
    var b = document.querySelector('[data-cart-badge]') || document.querySelector('#cartLink .count');
    return b ? (parseInt(b.textContent, 10) || 0) : 0;
  }

  document.addEventListener('submit', function (e) {
    var form = e.target, box = form.closest('.card-buy');
    if (!box || !window.fetch || e.defaultPrevented) return;
    e.preventDefault();
    var data = new FormData(form);
    if (e.submitter && e.submitter.name) data.set(e.submitter.name, e.submitter.value);
    box.classList.add('busy');
    var before = cartNow();
    // getAttribute: the form has a field named "action", which hides form.action in browsers.
    fetch(form.getAttribute('action') || location.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        document.querySelectorAll('.card-buy[data-product="' + box.getAttribute('data-product') + '"]').forEach(function (b) {
          if (b.querySelector('.n')) paintCard(b, res.qty, res.max);
        });
        var card = box.closest('.p-card');
        if (res.ok && res.count > before) flyToCart(card && card.querySelector('.img img'), function () { setCartCount(res.count); });
        else setCartCount(res.count);
        toast(res.message, res.ok);
      })
      .catch(function () { form.submit(); })
      .finally(function () { box.classList.remove('busy'); });
  });

  // Product page "Add to cart": add without leaving the page, then fly the main photo to the cart.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.hasAttribute('data-quick-add') || !window.fetch || e.defaultPrevented) return;
    e.preventDefault();
    var btn = form.querySelector('button[type=submit]'), before = cartNow();
    if (btn) { btn.disabled = true; btn.classList.add('is-adding'); }
    fetch(form.getAttribute('action') || location.href, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok && res.count > before) flyToCart(document.querySelector(form.getAttribute('data-fly-from')), function () { setCartCount(res.count); });
        else setCartCount(res.count);
        toast(res.message, res.ok);
      })
      .catch(function () { form.submit(); })
      .finally(function () { if (btn) { btn.disabled = false; btn.classList.remove('is-adding'); } });
  });

  // Horizontal product rails: <div class="rail" id="x"> + <button data-rail="#x" data-dir="1">
  document.querySelectorAll('[data-rail]').forEach(function (btn) {
    var rail = document.querySelector(btn.getAttribute('data-rail'));
    if (!rail) return;
    var sync = function () {
      var nav = btn.parentElement;
      nav.hidden = rail.scrollWidth <= rail.clientWidth + 4;
    };
    btn.addEventListener('click', function () {
      rail.scrollBy({ left: rail.clientWidth * 0.9 * parseInt(btn.getAttribute('data-dir'), 10), behavior: 'smooth' });
    });
    window.addEventListener('resize', sync);
    sync();
  });

  /* ---------- Installable app (PWA) ---------- */
  var manifest = document.querySelector('link[rel=manifest]');
  if (manifest && 'serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(new URL('sw.js', manifest.href).href).catch(function () {});
    });
  }
  var isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var deferredPrompt = null;
  var banner = document.getElementById('installBanner'), sheet = document.getElementById('installSheet');
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  };
  if (isStandalone) document.documentElement.classList.add('is-app');

  var isAndroid = /android/i.test(navigator.userAgent);
  var isSamsung = /samsungbrowser/i.test(navigator.userAgent);
  // Phones can always install: one tap where the browser offers it, step-by-step instructions otherwise.
  function canInstall() { return !isStandalone && (!!deferredPrompt || isIOS || isAndroid); }
  function refreshInstallUI() {
    document.querySelectorAll('.install-link').forEach(function (el) { el.hidden = !canInstall(); });
    var snoozed = Number(store.get('jm-install-snooze') || 0) > Date.now();
    var phone = window.matchMedia('(max-width: 900px)').matches;
    if (banner) banner.hidden = !(canInstall() && phone && !snoozed);
    document.body.classList.toggle('has-install-banner', !!banner && !banner.hidden);
  }
  function showSheet(open) {
    if (!sheet) return;
    if (open) {
      var which = isIOS ? 'ios' : (isSamsung ? 'samsung' : 'android');
      sheet.querySelectorAll('[data-steps]').forEach(function (ol) { ol.hidden = ol.getAttribute('data-steps') !== which; });
    }
    sheet.hidden = !open;
    document.body.classList.toggle('drawer-open', open);
  }
  function install() {
    if (deferredPrompt) {
      var p = deferredPrompt;
      deferredPrompt = null; // a prompt can only be used once
      p.prompt();
      p.userChoice.finally(refreshInstallUI);
    } else {
      showSheet(true); // browser didn't offer one-tap install: show the steps for this phone
    }
  }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferredPrompt = e; refreshInstallUI(); });
  window.addEventListener('appinstalled', function () { deferredPrompt = null; store.set('jm-install-snooze', String(Date.now() + 3650 * 864e5)); refreshInstallUI(); });
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-install-app]')) { e.preventDefault(); install(); }
    if (e.target.closest('[data-install-dismiss]')) { store.set('jm-install-snooze', String(Date.now() + 14 * 864e5)); refreshInstallUI(); }
    if (e.target.closest('[data-install-close]')) showSheet(false);
  });
  setTimeout(refreshInstallUI, 1500); // let the page settle before showing the banner

  /* ---------- App-style page loader (phones) ---------- */
  var loader = document.getElementById('pageLoader');
  if (loader) {
    var phoneUI = function () { return window.matchMedia('(max-width: 900px)').matches; };
    var hideLoader = function () { loader.classList.remove('show'); loader.classList.add('done'); };
    var showLoader = function () {
      if (!phoneUI()) return;
      loader.classList.remove('done'); loader.classList.add('show');
      setTimeout(hideLoader, 8000); // never block the screen if the next page is slow or the download didn't navigate
    };
    // First load: hide once the page is ready (images may keep loading behind it).
    if (document.readyState === 'complete') hideLoader(); else window.addEventListener('load', hideLoader);
    setTimeout(hideLoader, 1500);
    window.addEventListener('pageshow', hideLoader); // coming back with the Back button

    // Leaving the page: links to other pages on this site, and normal form submits.
    document.addEventListener('click', function (e) {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      var a = e.target.closest('a[href]');
      if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
      var href = a.getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^(tel|mailto|sms|javascript|whatsapp):/i.test(href)) return;
      var to = new URL(a.href, location.href);
      if (to.origin !== location.origin) return;
      if (to.pathname === location.pathname && to.search === location.search) return; // same page / anchor
      showLoader();
    });
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (form.target && form.target !== '_self') return;
      setTimeout(function () { if (!e.defaultPrevented) showLoader(); }, 0); // skip forms handled without leaving (e.g. add to cart)
    });
  }

  window.AFM = {
    money: function (v) { return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  };
})();
