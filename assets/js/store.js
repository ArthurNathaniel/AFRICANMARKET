/* Jenmond African Market - Storefront behaviour */
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

  window.AFM = {
    money: function (v) { return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  };
})();
