/* Jenmond Mobile African Market - Admin panel behaviour */
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

  // White background: <input type="file" data-white-bg> + <input type="checkbox" id="whiteBgToggle">
  // Removes the photo background in the browser and flattens it onto white before upload.
  var bgToggle = document.getElementById('whiteBgToggle');
  var bgInputs = document.querySelectorAll('input[type=file][data-white-bg]');
  if (bgToggle && bgInputs.length) {
    var BG_LIB = 'https://cdn.jsdelivr.net/npm/@imgly/background-removal@1.7.0/+esm';
    var MAX_SIDE = 2000;
    var libPromise = null;
    var pending = 0;
    var form = bgToggle.closest('form');

    var loadLib = function () {
      if (!libPromise) libPromise = import(BG_LIB).catch(function (err) { libPromise = null; throw err; });
      return libPromise;
    };
    var setStatus = function (input, text) {
      var el = document.querySelector('[data-white-bg-status="' + input.name + '"]');
      if (el) el.textContent = text;
    };
    // Shows the given files in the input's preview (main image or gallery grid).
    var showPreview = function (input, files, processing) {
      var img = document.getElementById(input.getAttribute('data-preview'));
      if (img && files[0]) {
        img.src = URL.createObjectURL(files[0]);
        img.classList.toggle('is-processing', processing);
      }
      var grid = document.querySelector('[data-white-bg-preview="' + input.name + '"]');
      if (grid) {
        grid.innerHTML = '';
        grid.hidden = !files.length;
        files.forEach(function (f) {
          var thumb = document.createElement('img');
          thumb.src = URL.createObjectURL(f);
          thumb.alt = f.name;
          thumb.title = f.name;
          if (processing) thumb.className = 'is-processing';
          grid.appendChild(thumb);
        });
      }
    };
    var setFiles = function (input, files) {
      var dt = new DataTransfer();
      files.forEach(function (f) { dt.items.add(f); });
      input.files = dt.files;
      showPreview(input, files, false);
    };
    var toWhiteJpeg = function (cutout, name) {
      return createImageBitmap(cutout).then(function (bmp) {
        var scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(bmp.width * scale);
        canvas.height = Math.round(bmp.height * scale);
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
        return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.92); });
      }).then(function (blob) {
        return new File([blob], name.replace(/\.[^.]+$/, '') + '-white.jpg', { type: 'image/jpeg' });
      });
    };
    var process = function (input) {
      var originals = input._originals || [];
      var token = input._bgToken = (input._bgToken || 0) + 1;
      if (!originals.length) { setStatus(input, ''); return; }
      if (!bgToggle.checked) { setFiles(input, originals); setStatus(input, ''); return; }

      pending++;
      var done = 0;
      showPreview(input, originals, true);
      setStatus(input, 'Removing background… (first run downloads the AI model, this can take a minute)');
      loadLib().then(function (lib) {
        return Promise.all(originals.map(function (file) {
          return lib.removeBackground(file).then(function (cutout) {
            return toWhiteJpeg(cutout, file.name);
          }).then(function (out) {
            done++;
            if (input._bgToken === token) setStatus(input, 'Removing background… ' + done + '/' + originals.length);
            return out;
          });
        }));
      }).then(function (files) {
        if (input._bgToken !== token) return;
        setFiles(input, files);
        setStatus(input, 'Background removed — ' + files.length + ' image' + (files.length > 1 ? 's' : '') + ' ready on white.');
      }).catch(function (err) {
        if (input._bgToken !== token) return;
        console.error(err);
        setFiles(input, originals);
        setStatus(input, 'Could not remove the background, the original image will be uploaded.');
      }).finally(function () { pending--; });
    };

    bgInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        input._originals = Array.prototype.slice.call(input.files || []);
        process(input);
      });
    });
    bgToggle.addEventListener('change', function () { bgInputs.forEach(process); });
    if (form) form.addEventListener('submit', function (e) {
      if (pending > 0) {
        e.preventDefault();
        window.alert('Please wait, images are still being processed.');
      }
    });
  }

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
