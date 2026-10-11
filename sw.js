/* Jenmond Mobile African Market - service worker (installable app + offline page).
 * Pages always come from the network (fresh prices, cart and stock); only when offline
 * is the offline page shown. Images, CSS and JS are cached so the app opens fast. */
'use strict';
var VERSION = 'jenmond-v1';
var BASE = new URL('./', self.location).pathname;           // site root, works in a sub-folder too
var OFFLINE_URL = BASE + 'offline.html';
var PRECACHE = [OFFLINE_URL, BASE + 'assets/img/icon-192.png', BASE + 'assets/img/logo-badge-200.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(PRECACHE); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request, url = new URL(req.url);
  // Only same-site GETs; never touch admin, payments, checkout or webhooks.
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  var path = url.pathname.slice(BASE.length - 1);
  if (/^\/(admin\/|checkout\.php|pay\.php|order-success\.php|stripe-webhook\.php|whatsapp-order\.php|cart\.php)/.test(path)) return;

  // Pages: network first, offline page as the fallback.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE_URL); }));
    return;
  }

  // Static files (versioned ?v=… URLs) and product photos: cache first, refresh in the background.
  if (/^\/(assets|uploads)\//.test(path)) {
    e.respondWith(caches.open(VERSION).then(function (cache) {
      return cache.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) cache.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
  }
});
