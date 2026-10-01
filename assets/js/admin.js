/* Jenmond African Market - Admin panel behaviour */
(function () {
  'use strict';

  // Mobile sidebar
  document.querySelectorAll('[data-toggle-nav]').forEach(function (btn) {
    btn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  });
  document.addEventListener('click', function (e) {
    if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar') && !e.target.closest('[data-toggle-nav]')) {
      document.body.classList.remove('nav-open');
    }
  });

  // Dropdowns
  document.querySelectorAll('[data-dropdown]').forEach(function (btn) {
    var menu = document.getElementById(btn.getAttribute('data-dropdown'));
    btn.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('open'); });
  });
  document.addEventListener('click', function () {
    document.querySelectorAll('.dropdown.open').forEach(function (d) { d.classList.remove('open'); });
  });

  // Confirm before destructive submits: <form data-confirm="Delete this?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Dismissable alerts
  document.querySelectorAll('.alert .close').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('.alert').remove(); });
  });

  // Show / hide password
  document.querySelectorAll('.pw-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = b.parentElement.querySelector('input');
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  // Image preview: <input type="file" data-preview="imgId">
  document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
    input.addEventListener('change', function () {
      var img = document.getElementById(input.getAttribute('data-preview'));
      if (img && input.files && input.files[0]) img.src = URL.createObjectURL(input.files[0]);
    });
  });

  // Auto slug: <input data-slug-source="#slug">
  document.querySelectorAll('[data-slug-target]').forEach(function (src) {
    var target = document.querySelector(src.getAttribute('data-slug-target'));
    if (!target) return;
    var touched = target.value !== '';
    target.addEventListener('input', function () { touched = true; });
    src.addEventListener('input', function () {
      if (touched) return;
      target.value = src.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    });
  });

  // Modals: <button data-modal="id"> ... <div class="modal-backdrop" id="id">
  document.querySelectorAll('[data-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var m = document.getElementById(btn.getAttribute('data-modal'));
      if (!m) return;
      // Copy data-fill-* attributes into fields named the same inside the modal
      Object.keys(btn.dataset).forEach(function (k) {
        if (k.indexOf('fill') !== 0) return;
        var name = k.slice(4).replace(/^[A-Z]/, function (c) { return c.toLowerCase(); })
                             .replace(/[A-Z]/g, function (c) { return '_' + c.toLowerCase(); });
        var field = m.querySelector('[name="' + name + '"]');
        if (field) field.value = btn.dataset[k];
        var text = m.querySelector('[data-text="' + name + '"]');
        if (text) text.textContent = btn.dataset[k];
      });
      m.classList.add('open');
      var first = m.querySelector('input:not([type=hidden]), select, textarea');
      if (first) first.focus();
    });
  });
  document.querySelectorAll('.modal-backdrop').forEach(function (m) {
    m.addEventListener('click', function (e) {
      if (e.target === m || e.target.closest('[data-close]')) m.classList.remove('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(function (m) { m.classList.remove('open'); });
  });

  // Chart.js defaults matching the admin theme
  if (window.Chart) {
    Chart.defaults.font.family = "'DM Sans', 'Segoe UI', system-ui, sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#57534a';
    Chart.defaults.borderColor = '#ede8de';
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.plugins.tooltip.backgroundColor = '#1d1b16';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.cornerRadius = 8;
    Chart.defaults.plugins.tooltip.titleFont = { weight: '700' };
    Chart.defaults.maintainAspectRatio = false;
  }

  window.AFM = {
    // Run chart code after web fonts load, so Chart.js measures labels with the real font.
    ready: function (fn) {
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(fn); else fn();
    },
    money: function (v) {
      return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    },
    moneyExact: function (v) {
      return '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  };
})();
