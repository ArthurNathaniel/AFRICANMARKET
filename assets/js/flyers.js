/* Jenmond Mobile African Market - Flyer maker (admin/flyers.php) */
(function () {
  'use strict';
  var DATA = JSON.parse(document.getElementById('flyerData').textContent);
  var canvas = document.getElementById('flyerCanvas');
  var ctx = canvas.getContext('2d');
  var $ = function (id) { return document.getElementById(id); };
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  var SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DISPLAY = '"Bricolage Grotesque", "DM Sans", system-ui, sans-serif';
  var BODY = '"DM Sans", system-ui, sans-serif';
  var MAX_PICKS = 4;

  /* ---------- Themes ---------- */
  var THEMES = {
    newmonth: { bg1: '#0d4a32', bg2: '#062519', ink: '#ffffff', muted: '#cfe3d7', accent: '#e2a022', accentInk: '#072e21', footBg: '#e2a022', footInk: '#072e21', deco: 'confetti' },
    deal:     { bg1: '#d2582c', bg2: '#8a2b12', ink: '#ffffff', muted: '#ffe3d4', accent: '#ffd23f', accentInk: '#5a1a08', footBg: '#072e21', footInk: '#ffffff', deco: 'burst' },
    arrival:  { bg1: '#fdf8ee', bg2: '#f1e2c4', ink: '#0f4a2f', muted: '#4f5f55', accent: '#0f4a2f', accentInk: '#ffffff', footBg: '#0f4a2f', footInk: '#ffffff', deco: 'dots' },
    flash:    { bg1: '#e63946', bg2: '#7a0f1c', ink: '#ffffff', muted: '#ffe1e3', accent: '#ffd23f', accentInk: '#5a0a12', footBg: '#1d1b16', footInk: '#ffffff', deco: 'burst' },
    weekend:  { bg1: '#0f766e', bg2: '#063b37', ink: '#ffffff', muted: '#d2f1ec', accent: '#fbbf24', accentInk: '#063b37', footBg: '#fbbf24', footInk: '#063b37', deco: 'dots' },
    bestseller: { bg1: '#f7c948', bg2: '#e0981c', ink: '#3a2300', muted: '#5a3a07', accent: '#0f4a2f', accentInk: '#ffffff', footBg: '#0f4a2f', footInk: '#ffffff', deco: 'star' },
    restock:  { bg1: '#16324f', bg2: '#0a1a2b', ink: '#ffffff', muted: '#d6e4f5', accent: '#f59e0b', accentInk: '#0a1a2b', footBg: '#f59e0b', footInk: '#0a1a2b', deco: 'waves' },
    combo:    { bg1: '#5b21b6', bg2: '#2e1065', ink: '#ffffff', muted: '#e9dcff', accent: '#fde047', accentInk: '#2e1065', footBg: '#fde047', footInk: '#2e1065', deco: 'confetti' },
    freeship: { bg1: '#0d4a32', bg2: '#062519', ink: '#ffffff', muted: '#cfe3d7', accent: '#e2a022', accentInk: '#072e21', footBg: '#c96a1b', footInk: '#ffffff', deco: 'ankara' },
    marketday:{ bg1: '#8a3412', bg2: '#3b1206', ink: '#ffffff', muted: '#ffe4d1', accent: '#fbbf24', accentInk: '#3b1206', footBg: '#0d4a32', footInk: '#ffffff', deco: 'kente' },
    thanks:   { bg1: '#fff7ed', bg2: '#fde2c8', ink: '#7c2d12', muted: '#6b4a35', accent: '#c2410c', accentInk: '#ffffff', footBg: '#7c2d12', footInk: '#ffffff', deco: 'ankara' }
  };
  // Background colour choices (override the flyer type's colours).
  var PALETTES = {
    forest:    { bg1: '#0d4a32', bg2: '#062519', ink: '#ffffff', muted: '#cfe3d7', accent: '#e2a022', accentInk: '#072e21', footBg: '#e2a022', footInk: '#072e21' },
    sunset:    { bg1: '#f08a24', bg2: '#b4321b', ink: '#ffffff', muted: '#fff0e0', accent: '#072e21', accentInk: '#ffffff', footBg: '#072e21', footInk: '#ffffff' },
    ruby:      { bg1: '#b3122e', bg2: '#5c0717', ink: '#ffffff', muted: '#ffdfe5', accent: '#ffd23f', accentInk: '#5c0717', footBg: '#1d1b16', footInk: '#ffffff' },
    royal:     { bg1: '#5b21b6', bg2: '#2e1065', ink: '#ffffff', muted: '#e9dcff', accent: '#fde047', accentInk: '#2e1065', footBg: '#fde047', footInk: '#2e1065' },
    midnight:  { bg1: '#1e3a8a', bg2: '#0b1533', ink: '#ffffff', muted: '#dbe6ff', accent: '#fbbf24', accentInk: '#0b1533', footBg: '#fbbf24', footInk: '#0b1533' },
    teal:      { bg1: '#0f766e', bg2: '#063b37', ink: '#ffffff', muted: '#d2f1ec', accent: '#fbbf24', accentInk: '#063b37', footBg: '#fbbf24', footInk: '#063b37' },
    gold:      { bg1: '#f7c948', bg2: '#e0981c', ink: '#3a2300', muted: '#5a3a07', accent: '#0f4a2f', accentInk: '#ffffff', footBg: '#0f4a2f', footInk: '#ffffff' },
    cream:     { bg1: '#fdf8ee', bg2: '#f1e2c4', ink: '#0f4a2f', muted: '#4f5f55', accent: '#c96a1b', accentInk: '#ffffff', footBg: '#0f4a2f', footInk: '#ffffff' },
    blackgold: { bg1: '#262626', bg2: '#050505', ink: '#ffffff', muted: '#d4d4d4', accent: '#e2b54a', accentInk: '#000000', footBg: '#e2b54a', footInk: '#000000' },
    ghana:     { bg1: '#0e6b3a', bg2: '#06371d', ink: '#ffffff', muted: '#d5eedd', accent: '#fcd116', accentInk: '#3a2a00', footBg: '#ce1126', footInk: '#ffffff' },
    naija:     { bg1: '#008751', bg2: '#004d2e', ink: '#ffffff', muted: '#d8f0e4', accent: '#ffffff', accentInk: '#006b40', footBg: '#ffffff', footInk: '#004d2e' }
  };
  var HOLIDAYS = [
    { id: 'christmas', name: 'Christmas', eyebrow: "SEASON'S GREETINGS", headline: 'Merry Christmas', sub: 'Celebrate with the flavours of home — jollof spices, chin chin, Milo and more, delivered to your door.', offer: 'HOLIDAY SPECIALS',
      theme: { bg1: '#9b1c1c', bg2: '#4a0b0b', ink: '#ffffff', muted: '#ffdede', accent: '#f4c542', accentInk: '#4a0b0b', footBg: '#14532d', footInk: '#ffffff', deco: 'sparkle' } },
    { id: 'newyear', name: 'New Year', eyebrow: 'CHEERS TO A NEW YEAR', headline: 'Happy New Year {NEXTYEAR}', sub: 'Thank you for shopping with us. Start the year stocked up on the African brands you love.', offer: '',
      theme: { bg1: '#1b1640', bg2: '#07051a', ink: '#ffffff', muted: '#d9d4ff', accent: '#e2b54a', accentInk: '#1b1640', footBg: '#e2b54a', footInk: '#1b1640', deco: 'sparkle' } },
    { id: 'valentine', name: "Valentine's Day", eyebrow: 'SHARE THE LOVE', headline: "Happy Valentine's Day", sub: 'Treat someone special to a taste of home this Valentine’s.', offer: 'WITH LOVE',
      theme: { bg1: '#c2185b', bg2: '#6d0d33', ink: '#ffffff', muted: '#ffd6e6', accent: '#ffffff', accentInk: '#9c1350', footBg: '#4a0a24', footInk: '#ffffff', deco: 'confetti' } },
    { id: 'ghana', name: 'Ghana Independence Day (Mar 6)', eyebrow: '6TH MARCH', headline: 'Happy Independence Day, Ghana', sub: 'Celebrating Ghana with the tastes of home — shipped anywhere in the USA.', offer: 'AYEKOO!',
      theme: { bg1: '#0e6b3a', bg2: '#06371d', ink: '#ffffff', muted: '#d5eedd', accent: '#fcd116', accentInk: '#3a2a00', footBg: '#ce1126', footInk: '#ffffff', deco: 'star' } },
    { id: 'easter', name: 'Easter', eyebrow: 'HE IS RISEN', headline: 'Happy Easter', sub: 'Wishing you and your family a joyful Easter. Stock up for the celebrations!', offer: '',
      theme: { bg1: '#6a4c93', bg2: '#35214f', ink: '#ffffff', muted: '#ece2ff', accent: '#ffd166', accentInk: '#35214f', footBg: '#ffd166', footInk: '#35214f', deco: 'confetti' } },
    { id: 'mothers', name: "Mother's Day", eyebrow: 'FOR THE QUEENS', headline: "Happy Mother's Day", sub: 'Celebrate mum with her favourite tastes of home.', offer: '',
      theme: { bg1: '#b5487a', bg2: '#5e1d3c', ink: '#ffffff', muted: '#ffe0ef', accent: '#ffd1e3', accentInk: '#5e1d3c', footBg: '#5e1d3c', footInk: '#ffffff', deco: 'sparkle' } },
    { id: 'fathers', name: "Father's Day", eyebrow: 'FOR THE KINGS', headline: "Happy Father's Day", sub: 'Celebrate dad with the flavours he grew up with.', offer: '',
      theme: { bg1: '#1f3b5c', bg2: '#0b1a2b', ink: '#ffffff', muted: '#d6e4f5', accent: '#e2a022', accentInk: '#0b1a2b', footBg: '#e2a022', footInk: '#0b1a2b', deco: 'dots' } },
    { id: 'july4', name: 'Independence Day (July 4)', eyebrow: 'HAPPY 4TH OF JULY', headline: 'Happy Independence Day', sub: 'Celebrate the 4th with the African flavours you love — delivered across all 50 states.', offer: '',
      theme: { bg1: '#1d3557', bg2: '#0b1527', ink: '#ffffff', muted: '#dbe6f5', accent: '#e63946', accentInk: '#ffffff', footBg: '#e63946', footInk: '#ffffff', deco: 'star' } },
    { id: 'nigeria', name: 'Nigeria Independence Day (Oct 1)', eyebrow: '1ST OCTOBER', headline: 'Happy Independence Day, Nigeria', sub: 'Celebrating Naija with the tastes of home — shipped anywhere in the USA.', offer: '',
      theme: { bg1: '#008751', bg2: '#004d2e', ink: '#ffffff', muted: '#d8f0e4', accent: '#ffffff', accentInk: '#006b40', footBg: '#ffffff', footInk: '#004d2e', deco: 'star' } },
    { id: 'thanksgiving', name: 'Thanksgiving', eyebrow: 'GIVING THANKS', headline: 'Happy Thanksgiving', sub: 'Grateful for every one of our customers. Bring the taste of home to your table.', offer: '',
      theme: { bg1: '#9c4a1a', bg2: '#4d220a', ink: '#ffffff', muted: '#ffe6cf', accent: '#f2b134', accentInk: '#4d220a', footBg: '#2f3e1f', footInk: '#ffffff', deco: 'dots' } },
    { id: 'blackfriday', name: 'Black Friday', eyebrow: 'BLACK FRIDAY', headline: 'Black Friday Deals', sub: 'Our biggest savings of the year — limited stock, shop now!', offer: 'MEGA SALE',
      theme: { bg1: '#1a1a1a', bg2: '#000000', ink: '#ffffff', muted: '#d0d0d0', accent: '#e2a022', accentInk: '#000000', footBg: '#e2a022', footInk: '#000000', deco: 'burst' } },
    { id: 'eid', name: 'Eid', eyebrow: 'EID MUBARAK', headline: 'Eid Mubarak', sub: 'Wishing you peace and joy. Celebrate with the flavours of home.', offer: '',
      theme: { bg1: '#0f5e57', bg2: '#06302c', ink: '#ffffff', muted: '#d3efeb', accent: '#e8c46a', accentInk: '#06302c', footBg: '#e8c46a', footInk: '#06302c', deco: 'star' } },
    { id: 'custom', name: 'Other holiday', eyebrow: 'SEASON’S GREETINGS', headline: 'Happy Holidays', sub: 'Celebrate with the tastes of home, delivered to your door.', offer: '',
      theme: { bg1: '#0d4a32', bg2: '#062519', ink: '#ffffff', muted: '#cfe3d7', accent: '#e2a022', accentInk: '#072e21', footBg: '#c96a1b', footInk: '#ffffff', deco: 'sparkle' } }
  ];

  /* ---------- State + form ---------- */
  var picks = [];
  var now = new Date();
  var type = function () { return document.querySelector('input[name=type]:checked').value; };
  var holiday = function () { return HOLIDAYS.find(function (h) { return h.id === $('fHoliday').value; }) || HOLIDAYS[0]; };
  function hexMix(hex, toward, amt) {
    var n = parseInt(hex.slice(1), 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255, t = toward === 'white' ? 255 : 0;
    var c = function (v) { return Math.round(v + (t - v) * amt).toString(16).padStart(2, '0'); };
    return '#' + c(r) + c(g) + c(b);
  }
  function customTheme(hex) {
    var n = parseInt(hex.slice(1), 16), lum = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255, dark = lum < 0.6;
    return dark
      ? { bg1: hex, bg2: hexMix(hex, 'black', 0.5), ink: '#ffffff', muted: 'rgba(255,255,255,0.85)', accent: '#e2a022', accentInk: '#1d1b16', footBg: hexMix(hex, 'black', 0.65), footInk: '#ffffff' }
      : { bg1: hex, bg2: hexMix(hex, 'black', 0.18), ink: '#1d1b16', muted: '#3f3a33', accent: '#0f4a2f', accentInk: '#ffffff', footBg: '#0f4a2f', footInk: '#ffffff' };
  }
  // Type colours, then the chosen background colour, then the chosen pattern.
  var theme = function () {
    var base = type() === 'holiday' ? holiday().theme : THEMES[type()], bg = $('fBg').value, pat = $('fPattern').value, out = Object.assign({}, base);
    if (PALETTES[bg]) Object.assign(out, PALETTES[bg]);
    if (bg === 'custom') Object.assign(out, customTheme($('fColor').value));
    if (bg === 'photo') Object.assign(out, { ink: '#ffffff', muted: 'rgba(255,255,255,0.9)', photo: true });
    if (pat !== 'auto') out.deco = pat;
    return out;
  };
  var money = function (v) { return '$' + Number(v).toFixed(2); };
  var discount = function (p) { return p.compare && p.compare > p.price ? Math.round((1 - p.price / p.compare) * 100) : 0; };

  MONTHS.forEach(function (m, i) { $('fMonth').add(new Option(m, i)); });
  $('fMonth').value = now.getDate() >= 20 ? (now.getMonth() + 1) % 12 : now.getMonth();
  HOLIDAYS.forEach(function (h) { $('fHoliday').add(new Option(h.name, h.id)); });
  // Suggest the next upcoming holiday by date.
  (function () {
    var y = now.getFullYear(), soon = [['valentine', 1, 14], ['ghana', 2, 6], ['july4', 6, 4], ['nigeria', 9, 1], ['thanksgiving', 10, 26], ['blackfriday', 10, 28], ['christmas', 11, 25], ['newyear', 11, 31]];
    var next = soon.find(function (s) { return new Date(y, s[1], s[2]) >= new Date(y, now.getMonth(), now.getDate()); });
    $('fHoliday').value = next ? next[0] : 'newyear';
  })();
  var mon = new Date(now); mon.setDate(now.getDate() - ((now.getDay() + 6) % 7));
  var sun = new Date(mon); sun.setDate(mon.getDate() + 6);
  $('fDates').value = SHORT[mon.getMonth()] + ' ' + mon.getDate() + ' – ' + SHORT[sun.getMonth()] + ' ' + sun.getDate();
  var fri = new Date(mon); fri.setDate(mon.getDate() + 4);
  var sat = new Date(mon); sat.setDate(mon.getDate() + 5);
  var weekendLabel = 'FRI ' + SHORT[fri.getMonth()].toUpperCase() + ' ' + fri.getDate() + ' – SUN ' + SHORT[sun.getMonth()].toUpperCase() + ' ' + sun.getDate();
  $('fWhen').value = 'SAT ' + SHORT[sat.getMonth()].toUpperCase() + ' ' + sat.getDate() + ' · 10AM – 4PM';
  $('fWebsite').value = DATA.website;
  $('fWhatsapp').value = DATA.whatsapp;
  $('fPhones').value = DATA.phones;

  function fillText() {
    var t = type(), month = MONTHS[+$('fMonth').value];
    var year = now.getFullYear() + (+$('fMonth').value < now.getMonth() ? 1 : 0);
    var maxOff = Math.max.apply(null, [0].concat(picks.map(discount)));
    var copy = {
      newmonth: ['WELCOME ' + month.toUpperCase() + ' ' + year, 'Happy New Month', 'Wishing you a blessed ' + month + '! Stock up on the African brands you love — delivered to your door.', ''],
      deal: [$('fDates').value.toUpperCase(), 'Deal of the Week', 'Big savings on your favourites — limited stock, order before it’s gone!', maxOff ? 'UP TO ' + maxOff + '% OFF' : 'HOT DEALS'],
      arrival: ['JUST LANDED', 'New Arrivals', 'Fresh from Africa to your door — be the first to try them.', 'NEW IN STOCK'],
      flash: ['TODAY ONLY', 'Flash Sale', 'Prices this low won’t last — grab yours before midnight!', maxOff ? maxOff + '% OFF' : 'LIMITED TIME'],
      weekend: [weekendLabel, 'Weekend Special', 'Stock up for the weekend — jollof, light soup and all your favourites, delivered.', ''],
      bestseller: ['CUSTOMER FAVOURITES', 'Our Best Sellers', 'The products our customers order again and again. Have you tried them yet?', 'TOP PICKS'],
      restock: ['YOU ASKED, WE DELIVERED', 'Back in Stock', 'Your favourites are back on the shelves — order now before they sell out again.', 'BACK AGAIN'],
      combo: ['BUNDLE & SAVE', 'Combo Deal', 'Get these together and save more — perfect for stocking up the pantry.', maxOff ? 'SAVE ' + maxOff + '%' : 'SAVE MORE'],
      freeship: ['ALL 50 STATES', 'Free Delivery to Your Door', 'Order your African groceries online — we ship anywhere in the USA.', 'FREE SHIPPING'],
      marketday: [$('fWhen').value.toUpperCase(), 'Market Day Near You', 'Our mobile market is coming to your area — shop fresh African groceries in person!', 'COME THROUGH'],
      thanks: ['FROM ALL OF US', 'Thank You for Shopping With Us', 'Your support keeps the taste of home alive. We appreciate every single order!', '']
    };
    var c = t === 'holiday'
      ? [holiday().eyebrow, holiday().headline.replace('{NEXTYEAR}', now.getMonth() === 11 ? now.getFullYear() + 1 : now.getFullYear()), holiday().sub, holiday().offer]
      : copy[t];
    $('fEyebrow').value = c[0]; $('fHeadline').value = c[1]; $('fSub').value = c[2]; $('fOffer').value = c[3];
  }

  function autoPick() {
    var list = DATA.products.filter(function (p) { return p.stock > 0; });
    if (!list.length) list = DATA.products.slice();
    var t = type();
    if (t === 'deal' || t === 'flash' || t === 'combo') {
      var deals = list.filter(discount).sort(function (a, b) { return discount(b) - discount(a); });
      if (deals.length) list = deals;
    } else if (t === 'bestseller') {
      list = list.slice().sort(function (a, b) { return b.sold - a.sold || b.featured - a.featured; });
    } else if (t !== 'arrival') {
      list = list.slice().sort(function (a, b) { return (b.featured - a.featured); });
    }
    picks = list.slice(0, MAX_PICKS);
    renderPicks();
  }

  function renderPicks() {
    var q = $('pickSearch').value.trim().toLowerCase(), box = $('picks');
    box.innerHTML = '';
    DATA.products.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) > -1; }).forEach(function (p) {
      var on = picks.some(function (x) { return x.id === p.id; });
      var row = document.createElement('label');
      row.className = 'flyer-pick' + (on ? ' on' : '');
      row.innerHTML = '<input type="checkbox"><img alt="" loading="lazy"><span class="nm"></span><span class="pr"></span>';
      row.querySelector('input').checked = on;
      row.querySelector('input').disabled = !on && picks.length >= MAX_PICKS;
      row.querySelector('img').src = p.image;
      row.querySelector('.nm').textContent = p.name;
      row.querySelector('.pr').textContent = money(p.price) + (discount(p) ? ' (−' + discount(p) + '%)' : '') + (p.stock > 0 ? '' : ' · sold out');
      row.querySelector('input').addEventListener('change', function (e) {
        if (e.target.checked) picks.push(p); else picks = picks.filter(function (x) { return x.id !== p.id; });
        renderPicks(); draw();
      });
      box.appendChild(row);
    });
    $('pickCount').textContent = '(' + picks.length + '/' + MAX_PICKS + ')';
  }

  function syncFields() {
    var t = type();
    document.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.getAttribute('data-for') !== t; });
  }

  /* ---------- Canvas helpers ---------- */
  var cache = {};
  function loadImage(src) {
    if (!cache[src]) cache[src] = new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { resolve(null); };
      img.src = src;
    });
    return cache[src];
  }
  function spacing(px) { if ('letterSpacing' in ctx) ctx.letterSpacing = px + 'px'; }
  function rrect(x, y, w, h, r) {
    ctx.beginPath();
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function wrap(text, maxW) {
    var words = String(text).split(/\s+/).filter(Boolean), lines = [], line = '';
    words.forEach(function (w) {
      var test = line ? line + ' ' + w : w;
      if (line && ctx.measureText(test).width > maxW) { lines.push(line); line = w; } else line = test;
    });
    if (line) lines.push(line);
    return lines;
  }
  function fit(text, maxW, maxLines, start, min, weight, family) {
    for (var size = start; size >= min; size -= 2) {
      ctx.font = weight + ' ' + size + 'px ' + family;
      var lines = wrap(text, maxW);
      if (lines.length <= maxLines && lines.every(function (l) { return ctx.measureText(l).width <= maxW; })) return { size: size, lines: lines };
    }
    ctx.font = weight + ' ' + min + 'px ' + family;
    return { size: min, lines: clamp(wrap(text, maxW), maxLines, maxW) };
  }
  function clamp(lines, max, maxW) {
    if (lines.length <= max) return lines;
    var out = lines.slice(0, max), last = out[max - 1] + '…';
    while (ctx.measureText(last).width > maxW && last.length > 2) last = last.slice(0, -2) + '…';
    out[max - 1] = last;
    return out;
  }
  function rng(seed) { return function () { seed |= 0; seed = seed + 0x6D2B79F5 | 0; var t = Math.imul(seed ^ seed >>> 15, 1 | seed); t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }
  function star(cx, cy, r, points, inner) {
    ctx.beginPath();
    for (var i = 0; i < points * 2; i++) {
      var rad = i % 2 ? r * inner : r, a = Math.PI / points * i - Math.PI / 2;
      ctx.lineTo(cx + Math.cos(a) * rad, cy + Math.sin(a) * rad);
    }
    ctx.closePath();
  }
  function kente(y, h, W) {
    var pattern = [['#e2a022', 18], ['#c4532d', 8], ['#1a8a5a', 14], ['#1d1b16', 4]], x = 0, i = 0;
    while (x < W) { var p = pattern[i++ % pattern.length]; ctx.fillStyle = p[0]; ctx.fillRect(x, y, p[1] * 1.6, h); x += p[1] * 1.6; }
  }

  function decorate(th, W, H) {
    if (th.deco === 'none') return;
    var r = rng(7 + W + H);
    ctx.save();
    if (th.deco === 'kente') {
      // Woven kente bands across the background
      ctx.globalAlpha = 0.16;
      for (var ky = 40; ky < H; ky += 190) { kente(ky, 34, W); ctx.fillStyle = th.accent; ctx.fillRect(0, ky + 44, W, 6); }
    } else if (th.deco === 'ankara') {
      // Ankara-style diamond lattice with dotted centres
      var step = 120;
      ctx.lineWidth = 3; ctx.strokeStyle = th.ink; ctx.fillStyle = th.accent;
      for (var ay = 0; ay < H + step; ay += step) {
        for (var ax = (ay / step) % 2 ? step / 2 : 0; ax < W + step; ax += step) {
          ctx.globalAlpha = 0.09;
          ctx.beginPath(); ctx.moveTo(ax, ay - step / 2); ctx.lineTo(ax + step / 2, ay); ctx.lineTo(ax, ay + step / 2); ctx.lineTo(ax - step / 2, ay); ctx.closePath(); ctx.stroke();
          ctx.beginPath(); ctx.moveTo(ax, ay - step / 4); ctx.lineTo(ax + step / 4, ay); ctx.lineTo(ax, ay + step / 4); ctx.lineTo(ax - step / 4, ay); ctx.closePath(); ctx.stroke();
          ctx.globalAlpha = 0.22; ctx.beginPath(); ctx.arc(ax, ay, 5, 0, Math.PI * 2); ctx.fill();
        }
      }
    } else if (th.deco === 'waves') {
      ctx.lineWidth = 3; ctx.strokeStyle = th.ink;
      for (var wv = 0; wv < 14; wv++) {
        ctx.globalAlpha = 0.05 + (wv % 3) * 0.03;
        ctx.beginPath();
        for (var wx = 0; wx <= W; wx += 12) ctx.lineTo(wx, 60 + wv * (H / 12) + Math.sin(wx / 110 + wv * 0.7) * 26);
        ctx.stroke();
      }
    } else if (th.deco === 'burst') {
      ctx.translate(W * 0.8, 230);
      for (var i = 0; i < 28; i++) {
        ctx.rotate(Math.PI / 14);
        ctx.fillStyle = i % 2 ? 'rgba(255,255,255,0.07)' : 'rgba(255,255,255,0.02)';
        ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(1600, -90); ctx.lineTo(1600, 90); ctx.closePath(); ctx.fill();
      }
    } else if (th.deco === 'confetti') {
      var cols = [th.accent, '#c96a1b', '#ffffff', '#1a8a5a'];
      for (var c = 0; c < 70; c++) {
        ctx.save();
        ctx.globalAlpha = 0.18 + r() * 0.3;
        ctx.fillStyle = cols[c % cols.length];
        ctx.translate(r() * W, r() * H * 0.55);
        ctx.rotate(r() * Math.PI);
        if (c % 3) ctx.fillRect(-7, -3, 14, 6); else { ctx.beginPath(); ctx.arc(0, 0, 5, 0, Math.PI * 2); ctx.fill(); }
        ctx.restore();
      }
    } else if (th.deco === 'sparkle' || th.deco === 'star') {
      for (var s = 0; s < 34; s++) {
        ctx.globalAlpha = 0.15 + r() * 0.45;
        ctx.fillStyle = s % 4 ? th.accent : '#ffffff';
        star(r() * W, r() * H * 0.6, 4 + r() * 13, th.deco === 'star' ? 5 : 4, th.deco === 'star' ? 0.45 : 0.25);
        ctx.fill();
      }
    } else {
      for (var d = 0; d < 46; d++) {
        ctx.globalAlpha = 0.07 + r() * 0.12;
        ctx.fillStyle = d % 2 ? th.accent : '#c96a1b';
        ctx.beginPath(); ctx.arc(r() * W, r() * H * 0.6, 6 + r() * 26, 0, Math.PI * 2); ctx.fill();
      }
    }
    ctx.restore();
    // Two soft rings for depth
    ctx.save();
    ctx.strokeStyle = th.ink; ctx.globalAlpha = 0.06; ctx.lineWidth = 50;
    ctx.beginPath(); ctx.arc(-40, H * 0.45, 260, 0, Math.PI * 2); ctx.stroke();
    ctx.beginPath(); ctx.arc(W + 60, H * 0.12, 220, 0, Math.PI * 2); ctx.stroke();
    ctx.restore();
  }

  function sticker(text, cx, cy, rad, th) {
    // Keep the words readable (30px+): grow the badge up to 45% to fit them, then shrink text if still needed.
    text = text.toUpperCase();
    var f = fit(text, rad * 1.3, 3, 48, 30, '800', DISPLAY);
    ctx.font = '800 ' + f.size + 'px ' + DISPLAY;
    var blockW = Math.max.apply(null, f.lines.map(function (l) { return ctx.measureText(l).width; }));
    var need = Math.hypot(blockW / 2, f.lines.length * f.size * 1.02 / 2) / 0.74;
    if (need > rad) rad = Math.min(need, rad * 1.45);
    if (need > rad + 1) f = fit(text, rad * 1.3, 3, 48, 18, '800', DISPLAY);
    ctx.save();
    ctx.translate(cx, cy); ctx.rotate(-0.2);
    ctx.shadowColor = 'rgba(0,0,0,0.25)'; ctx.shadowBlur = 24; ctx.shadowOffsetY = 8;
    ctx.fillStyle = th.accent; star(0, 0, rad, 18, 0.88); ctx.fill();
    ctx.shadowColor = 'transparent';
    ctx.strokeStyle = th.accentInk; ctx.globalAlpha = 0.35; ctx.setLineDash([6, 6]); ctx.lineWidth = 3;
    ctx.beginPath(); ctx.arc(0, 0, rad * 0.78, 0, Math.PI * 2); ctx.stroke();
    ctx.globalAlpha = 1; ctx.setLineDash([]);
    ctx.font = '800 ' + f.size + 'px ' + DISPLAY;
    ctx.fillStyle = th.accentInk; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    var lh = f.size * 1.02, y0 = -((f.lines.length - 1) * lh) / 2;
    f.lines.forEach(function (l, i) { ctx.fillText(l, 0, y0 + i * lh); });
    ctx.restore();
  }

  function card(p, img, x, y, w, h, opts) {
    ctx.save();
    ctx.shadowColor = 'rgba(0,0,0,0.22)'; ctx.shadowBlur = 30; ctx.shadowOffsetY = 12;
    ctx.fillStyle = '#ffffff'; rrect(x, y, w, h, 28); ctx.fill();
    ctx.restore();
    var pad = Math.max(12, Math.round(w * 0.06)), small = w < 250;
    var textH = opts.prices ? (small ? 104 : 124) : (small ? 70 : 84);
    var ix = x + pad, iy = y + pad, iw = w - pad * 2, ih = h - pad - textH;
    ctx.fillStyle = '#ffffff'; rrect(ix, iy, iw, ih, 18); ctx.fill();
    if (img) {
      var sc = Math.min((iw - 20) / img.width, (ih - 20) / img.height);
      var dw = img.width * sc, dh = img.height * sc;
      ctx.save(); rrect(ix, iy, iw, ih, 18); ctx.clip();
      ctx.drawImage(img, ix + (iw - dw) / 2, iy + (ih - dh) / 2, dw, dh);
      ctx.restore();
    }
    // Corner tag
    // Corner tag: NEW / BACK / ranking for those flyer types, otherwise the discount.
    var special = { arrival: 'NEW', restock: 'BACK', bestseller: '#' + (opts.index + 1) }[opts.type];
    var tag = special || (discount(p) ? '−' + discount(p) + '%' : '');
    if (tag) {
      ctx.font = '800 ' + (small ? 22 : 26) + 'px ' + DISPLAY;
      var tw = ctx.measureText(tag).width + 26, th = small ? 40 : 46;
      ctx.fillStyle = special ? '#e2a022' : '#d7263d';
      rrect(ix + 10, iy + 10, tw, th, th / 2); ctx.fill();
      ctx.fillStyle = special ? '#072e21' : '#ffffff';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(tag, ix + 10 + tw / 2, iy + 10 + th / 2 + 1);
    }
    // Name + price
    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = '#1d1b16';
    ctx.font = '700 ' + (small ? 20 : 24) + 'px ' + BODY;
    var lines = clamp(wrap(p.name, w - pad * 2), 2, w - pad * 2), nameTop = iy + ih + (small ? 28 : 34);
    lines.forEach(function (l, i) { ctx.fillText(l, x + w / 2, nameTop + i * (small ? 23 : 28)); });
    if (opts.prices) {
      var py = y + h - pad - 4, price = money(p.price);
      ctx.font = '800 ' + (small ? 30 : 38) + 'px ' + DISPLAY;
      var pw = ctx.measureText(price).width, was = discount(p) ? money(p.compare) : '';
      ctx.font = '600 ' + (small ? 19 : 22) + 'px ' + BODY;
      var ww = was ? ctx.measureText(was).width + 12 : 0, startX = x + w / 2 - (pw + ww) / 2;
      ctx.textAlign = 'left';
      ctx.font = '800 ' + (small ? 30 : 38) + 'px ' + DISPLAY; ctx.fillStyle = '#0f4a2f';
      ctx.fillText(price, startX, py);
      if (was) {
        ctx.font = '600 ' + (small ? 19 : 22) + 'px ' + BODY; ctx.fillStyle = '#8a8478';
        var wx = startX + pw + 12; ctx.fillText(was, wx, py - 3);
        ctx.strokeStyle = '#8a8478'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(wx - 2, py - 10); ctx.lineTo(wx + ww - 10, py - 10); ctx.stroke();
      }
    }
  }

  /* ---------- Draw ---------- */
  var drawToken = 0;
  async function draw() {
    var token = ++drawToken;
    var size = $('fSize').value.split('x').map(Number), W = size[0], H = size[1];
    var th = theme(), t = type(), tall = H / W > 1.5;
    var items = picks.slice(0, MAX_PICKS);
    var imgs = await Promise.all([loadImage(DATA.logo)].concat(items.map(function (p) { return loadImage(p.image); })));
    if (token !== drawToken) return;
    var logo = imgs[0];
    canvas.width = W; canvas.height = H;
    $('sizeLabel').textContent = W + ' × ' + H + ' px';

    // Background
    var g = ctx.createLinearGradient(0, 0, W * 0.4, H);
    if (th.photo && imgs[1]) {
      // Blurred, enlarged product photo with a dark tint in the flyer's colours
      var ph = imgs[1], cover = Math.max(W / ph.width, H / ph.height) * 1.25;
      ctx.save();
      if ('filter' in ctx) ctx.filter = 'blur(26px) saturate(1.3)';
      ctx.drawImage(ph, (W - ph.width * cover) / 2, (H - ph.height * cover) / 2, ph.width * cover, ph.height * cover);
      ctx.restore();
      g.addColorStop(0, hexMix(th.bg1, 'black', 0.35) + 'cc'); g.addColorStop(1, hexMix(th.bg2, 'black', 0.45) + 'e6');
    } else {
      g.addColorStop(0, th.bg1); g.addColorStop(1, th.bg2);
    }
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, H);
    decorate(th, W, H);
    kente(0, 14, W);

    var pad = 64, footH = tall ? 170 : 136, y = 50;

    // Header: logo + store name
    var logoS = tall ? 150 : 118;
    ctx.save();
    ctx.fillStyle = 'rgba(255,253,246,0.96)'; ctx.shadowColor = 'rgba(0,0,0,0.25)'; ctx.shadowBlur = 20;
    ctx.beginPath(); ctx.arc(pad + logoS / 2, y + logoS / 2, logoS / 2 + 5, 0, Math.PI * 2); ctx.fill();
    ctx.restore();
    if (logo) ctx.drawImage(logo, pad, y, logoS, logoS);
    ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
    ctx.fillStyle = th.ink; ctx.font = '800 ' + (tall ? 56 : 48) + 'px ' + DISPLAY; spacing(1);
    ctx.fillText('JENMOND', pad + logoS + 22, y + logoS / 2 + 4);
    ctx.fillStyle = th.ink === '#ffffff' ? th.accent : '#c96a1b'; ctx.font = '700 ' + (tall ? 22 : 19) + 'px ' + BODY; spacing(4);
    ctx.fillText('MOBILE AFRICAN MARKET', pad + logoS + 24, y + logoS / 2 + (tall ? 38 : 32));
    spacing(0);

    // Sticker
    var offer = $('fOffer').value.trim();
    if (offer) sticker(offer, W - pad - (tall ? 120 : 100), y + logoS / 2 + (tall ? 20 : 8), tall ? 128 : 108, th);

    // Eyebrow pill
    y += logoS + (tall ? 60 : 34);
    var eyebrow = $('fEyebrow').value.trim().toUpperCase();
    if (eyebrow) {
      ctx.font = '800 ' + (tall ? 28 : 24) + 'px ' + BODY; spacing(3);
      var ew = ctx.measureText(eyebrow).width + 48, eh = tall ? 58 : 50;
      ctx.fillStyle = th.accent; rrect(pad, y, ew, eh, eh / 2); ctx.fill();
      ctx.fillStyle = th.accentInk; ctx.textBaseline = 'middle'; ctx.fillText(eyebrow, pad + 24, y + eh / 2 + 1);
      spacing(0); ctx.textBaseline = 'alphabetic';
      y += eh + (tall ? 30 : 20);
    }

    // Headline + message
    var maxW = W - pad * 2 - (offer && !eyebrow ? 220 : 0);
    var hl = fit($('fHeadline').value.trim() || ' ', maxW, tall ? 3 : 2, tall ? 140 : 108, 52, '800', DISPLAY);
    ctx.fillStyle = th.ink; ctx.font = '800 ' + hl.size + 'px ' + DISPLAY; spacing(-1);
    hl.lines.forEach(function (l, i) { ctx.fillText(l, pad - 4, y + hl.size * 0.86 + i * hl.size * 0.98); });
    spacing(0);
    y += hl.size * 0.98 * hl.lines.length + (tall ? 22 : 12);
    var sub = $('fSub').value.trim();
    if (sub) {
      var sz = tall ? 36 : 29;
      ctx.font = '500 ' + sz + 'px ' + BODY; ctx.fillStyle = th.muted;
      var subLines = clamp(wrap(sub, W - pad * 2), tall ? 3 : 2, W - pad * 2);
      subLines.forEach(function (l, i) { ctx.fillText(l, pad, y + sz + i * sz * 1.32); });
      y += sz * 1.32 * subLines.length + 6;
    }

    // Product cards
    var top = y + (tall ? 44 : 26), bottom = H - footH - (tall ? 56 : 34), availW = W - pad * 2, availH = bottom - top, n = items.length;
    if (n && availH > 160) {
      var gap = tall ? 28 : 22;
      // Pick the column count that gives the biggest cards in the space available.
      var best = null;
      for (var k = 1; k <= n; k++) {
        var rk = Math.ceil(n / k), wk = (availW - gap * (k - 1)) / k, hk = Math.min((availH - gap * (rk - 1)) / rk, wk * 1.5);
        var area = Math.min(wk, hk / 1.15) * hk;
        if (n === 4 && k === 3) continue; // avoid a lonely last card
        if (!best || area > best.area) best = { cols: k, area: area };
      }
      var cols = best.cols, rows = Math.ceil(n / cols);
      var cw = (availW - gap * (cols - 1)) / cols, ch = Math.min((availH - gap * (rows - 1)) / rows, cw * 1.5);
      cw = Math.min(cw, ch * 1.05);
      if (n === 1) cw = Math.min(cw, ch * 0.9);
      var gridH = ch * rows + gap * (rows - 1), gy = top + (availH - gridH) / 2;
      items.forEach(function (p, i) {
        var r = Math.floor(i / cols), c = i % cols, inRow = Math.min(cols, n - r * cols);
        var rowW = cw * inRow + gap * (inRow - 1), rx = (W - rowW) / 2;
        card(p, imgs[i + 1], rx + c * (cw + gap), gy + r * (ch + gap), cw, ch, { prices: $('fPrices').checked, type: t, index: i });
      });
    }

    // Footer
    var fy = H - footH;
    ctx.fillStyle = th.footBg; ctx.fillRect(0, fy, W, footH);
    kente(fy - 10, 10, W);
    var web = $('fWebsite').value.trim(), wa = $('fWhatsapp').value.trim(), phones = $('fPhones').value.trim();
    var big = tall ? 38 : 32, small = tall ? 25 : 21, line1 = fy + footH / 2 - (tall ? 6 : 4), line2 = line1 + small + (tall ? 18 : 12);
    // Shrink the top line until the website and WhatsApp number fit side by side.
    for (; big > 20; big -= 1) {
      ctx.font = '800 ' + big + 'px ' + DISPLAY;
      if (ctx.measureText(web || DATA.store).width + (wa ? ctx.measureText('WhatsApp ' + wa).width : 0) + 48 <= W - pad * 2) break;
    }
    for (; small > 14; small -= 1) {
      ctx.font = '600 ' + small + 'px ' + BODY;
      if (ctx.measureText(DATA.freeShip ? 'Free shipping · All 50 states' : 'Order online or on WhatsApp').width + (phones ? ctx.measureText('Call ' + phones).width : 0) + 48 <= W - pad * 2) break;
    }
    ctx.fillStyle = th.footInk; ctx.textAlign = 'left';
    ctx.font = '800 ' + big + 'px ' + DISPLAY; ctx.fillText(web || DATA.store, pad, line1);
    ctx.font = '600 ' + small + 'px ' + BODY; ctx.globalAlpha = 0.9;
    ctx.fillText(DATA.freeShip ? 'Free shipping · All 50 states' : 'Order online or on WhatsApp', pad, line2);
    ctx.globalAlpha = 1; ctx.textAlign = 'right';
    if (wa) { ctx.font = '800 ' + big + 'px ' + DISPLAY; ctx.fillText('WhatsApp ' + wa, W - pad, line1); }
    if (phones) { ctx.font = '600 ' + small + 'px ' + BODY; ctx.globalAlpha = 0.9; ctx.fillText('Call ' + phones, W - pad, line2); ctx.globalAlpha = 1; }
    ctx.textAlign = 'left';
  }

  /* ---------- Export ---------- */
  function fileName() {
    var d = new Date(), stamp = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    var label = type() === 'holiday' ? holiday().id : type();
    return 'jenmond-' + label + '-' + stamp + '.png';
  }
  $('downloadBtn').addEventListener('click', function () {
    canvas.toBlob(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = fileName();
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
    }, 'image/png');
  });
  if (navigator.canShare && window.File) {
    try {
      if (navigator.canShare({ files: [new File([''], 'x.png', { type: 'image/png' })] })) {
        $('shareBtn').hidden = false;
        $('shareBtn').addEventListener('click', function () {
          canvas.toBlob(function (blob) {
            navigator.share({ files: [new File([blob], fileName(), { type: 'image/png' })], title: $('fHeadline').value }).catch(function () {});
          }, 'image/png');
        });
      }
    } catch (e) { /* sharing not supported */ }
  }

  /* ---------- Wire up ---------- */
  document.querySelectorAll('input[name=type]').forEach(function (r) {
    r.addEventListener('change', function () { syncFields(); autoPick(); fillText(); draw(); });
  });
  $('fHoliday').addEventListener('change', function () { fillText(); draw(); });
  $('fMonth').addEventListener('change', function () { fillText(); draw(); });
  $('fDates').addEventListener('input', function () { $('fEyebrow').value = $('fDates').value.toUpperCase(); draw(); });
  $('fWhen').addEventListener('input', function () { $('fEyebrow').value = $('fWhen').value.toUpperCase(); draw(); });
  $('fBg').addEventListener('change', function () { $('fColor').hidden = $('fBg').value !== 'custom'; draw(); });
  $('fColor').addEventListener('input', draw);
  $('fPattern').addEventListener('change', draw);
  $('autoPick').addEventListener('click', function () { autoPick(); if (type() === 'deal') fillText(); draw(); });
  $('pickSearch').addEventListener('input', renderPicks);
  ['fSize', 'fEyebrow', 'fHeadline', 'fSub', 'fOffer', 'fWebsite', 'fWhatsapp', 'fPhones', 'fPrices'].forEach(function (id) {
    $(id).addEventListener('input', draw); $(id).addEventListener('change', draw);
  });

  syncFields(); autoPick(); fillText();
  Promise.all([
    document.fonts.load('800 100px "Bricolage Grotesque"'), document.fonts.load('700 30px "DM Sans"'),
    document.fonts.load('500 30px "DM Sans"'), document.fonts.load('600 30px "DM Sans"'), document.fonts.load('800 30px "DM Sans"')
  ]).catch(function () {}).then(draw);
})();
