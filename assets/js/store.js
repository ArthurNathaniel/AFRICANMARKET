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
  document.addEventListener('submit', function (e) {
    var form = e.target, box = form.closest('.card-buy');
    if (!box || !window.fetch || e.defaultPrevented) return;
    e.preventDefault();
    var data = new FormData(form);
    if (e.submitter && e.submitter.name) data.set(e.submitter.name, e.submitter.value);
    box.classList.add('busy');
    fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        document.querySelectorAll('.card-buy[data-product="' + box.getAttribute('data-product') + '"]').forEach(function (b) {
          if (b.querySelector('.n')) paintCard(b, res.qty, res.max);
        });
        setCartCount(res.count);
        toast(res.message, res.ok);
      })
      .catch(function () { form.submit(); })
      .finally(function () { box.classList.remove('busy'); });
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

  window.AFM = {
    money: function (v) { return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  };
})();
