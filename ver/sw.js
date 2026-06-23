/* Service Worker — ImagenMed public portal */
/* Importante: subir este número cada vez que se modifique algún archivo en
   STATIC_ASSETS, para que los clientes con caché vieja reciban la versión
   nueva (el handler 'activate' ya purga las cachés con nombre distinto). */
var CACHE_NAME = 'imagenmed-v2';
var STATIC_ASSETS = [
  'daikon.min.js',
  'dicom-viewer.js',
  'image-tools.js',
  'visor-panel.js',
].map(function(f) { return '../assets/js/' + f; });

self.addEventListener('install', function(e) {
  e.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(STATIC_ASSETS);
    }).catch(function() { /* offline install — skip */ })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(k) { return k !== CACHE_NAME; })
            .map(function(k) { return caches.delete(k); })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(e) {
  if (e.request.method !== 'GET') return;
  var url = new URL(e.request.url);

  /* Network-first for PHP pages (always fresh data) */
  if (url.pathname.match(/\.php$/) || e.request.mode === 'navigate') {
    e.respondWith(
      fetch(e.request).catch(function() {
        return caches.match(e.request);
      })
    );
    return;
  }

  /* Cache-first for static assets (JS, CSS, images, fonts) */
  e.respondWith(
    caches.match(e.request).then(function(cached) {
      if (cached) return cached;
      return fetch(e.request).then(function(response) {
        if (response && response.ok && response.type !== 'opaque') {
          var clone = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(e.request, clone);
          });
        }
        return response;
      });
    })
  );
});
