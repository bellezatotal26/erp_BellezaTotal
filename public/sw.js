/* Seri ERP — Service Worker POS (Fase B)
 * Cachea el shell estático y la última página de caja para continuidad sin red.
 * Las APIs (ventas/caja) van network-first; no se cachean respuestas mutables.
 */
var CACHE_VERSION = 'seri-pos-v4';
var SHELL_CACHE = CACHE_VERSION + '-shell';
var PAGE_CACHE = CACHE_VERSION + '-pages';

var SHELL_ASSETS = [
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/caja.js',
  './assets/js/ventas.js',
  './assets/js/barcode.js',
  './assets/js/pos-offline.js',
  './manifest.webmanifest'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      return cache.addAll(SHELL_ASSETS.map(function (u) {
        return new Request(u, { cache: 'reload' });
      })).catch(function () {
        // Algunos assets pueden faltar en deploys parciales; no bloquear install.
        return Promise.all(SHELL_ASSETS.map(function (u) {
          return cache.add(u).catch(function () { return null; });
        }));
      });
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) {
        return k.indexOf('seri-pos-') === 0 && k.indexOf(CACHE_VERSION) !== 0;
      }).map(function (k) {
        return caches.delete(k);
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

function isApiRequest(url) {
  if (url.search && /[?&]action=/.test(url.search)) return true;
  if (/\/app\/(ventas|caja|dashboard)/.test(url.pathname) && url.search) return true;
  return false;
}

function isCajaNavigation(url) {
  return /\/app\/caja\/?$/.test(url.pathname) || /\/app\/caja\/?$/.test(url.href.replace(url.origin, ''));
}

function isStaticAsset(url) {
  return /\/assets\/(css|js)\//.test(url.pathname) || /manifest\.webmanifest$/.test(url.pathname);
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url;
  try {
    url = new URL(req.url);
  } catch (e) {
    return;
  }

  // Solo mismo origen.
  if (url.origin !== self.location.origin) return;

  // APIs y mutaciones: siempre red, sin cache.
  if (isApiRequest(url)) {
    return;
  }

  if (isStaticAsset(url)) {
    // Network-first: en demos/deploys el JS/CSS nuevo debe ganar al cache viejo.
    event.respondWith(
      fetch(req).then(function (res) {
        if (res && res.ok) {
          var copy = res.clone();
          caches.open(SHELL_CACHE).then(function (cache) {
            cache.put(req, copy);
          }).catch(function () {});
        }
        return res;
      }).catch(function () {
        return caches.open(SHELL_CACHE).then(function (cache) {
          return cache.match(req);
        });
      })
    );
    return;
  }

  // Solo Caja: network-first + shell offline. No interceptar el resto de /app/.
  if ((req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') !== -1)
      && isCajaNavigation(url)) {
    event.respondWith(
      fetch(req).then(function (res) {
        if (res && res.ok) {
          var copy = res.clone();
          caches.open(PAGE_CACHE).then(function (cache) {
            cache.put(req, copy);
            cache.put('seri-caja-offline-shell', copy.clone()).catch(function () {});
          });
        }
        return res;
      }).catch(function () {
        return caches.open(PAGE_CACHE).then(function (cache) {
          return cache.match(req).then(function (hit) {
            if (hit) return hit;
            return cache.match('seri-caja-offline-shell').then(function (shell) {
              if (shell) return shell;
              return new Response(
                '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Sin conexión</title>' +
                '<meta name="viewport" content="width=device-width,initial-scale=1">' +
                '<style>body{font-family:system-ui,sans-serif;padding:2rem;text-align:center;color:#334155}' +
                'h1{color:#0f172a;font-size:1.25rem}</style></head><body>' +
                '<h1>Sin conexión</h1>' +
                '<p>Abra Caja al menos una vez con internet para poder usarla offline.</p>' +
                '<p>Las ventas en cola se sincronizarán al recuperar la red.</p>' +
                '</body></html>',
                { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
              );
            });
          });
        });
      })
    );
  }
});

self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
