/* Seri ERP — Service Worker POS (PWA escritorio + offline Caja)
 * Cachea shell estático y la última página de caja (solo para uso offline).
 * APIs (ventas/caja) van network-first; no se cachean respuestas mutables.
 * Al logout: SERI_CLEAR_SENSITIVE borra caches de páginas (CSRF/sesión).
 * Al recuperar red: notifica a clientes para sincronizar cola de ventas.
 */
var CACHE_VERSION = 'seri-pos-v12';
var SHELL_CACHE = CACHE_VERSION + '-shell';
var PAGE_CACHE = CACHE_VERSION + '-pages';

var SHELL_ASSETS = [
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/money-input.js',
  './assets/js/caja.js',
  './assets/js/ventas.js',
  './assets/js/barcode.js',
  './assets/js/pos-offline.js',
  './assets/img/pwa-icon-192.png',
  './assets/img/pwa-icon-512.png',
  './assets/img/pwa-icon-maskable-512.png',
  './assets/img/favicon-32.png',
  './manifest.php'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      return cache.addAll(SHELL_ASSETS.map(function (u) {
        return new Request(u, { cache: 'reload' });
      })).catch(function () {
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
  return /\/app\/caja\/?$/.test(url.pathname)
    || /\/app\/caja\/?(\?|$)/.test(url.pathname + url.search)
    || /\/app\/caja\/?$/.test(url.href.replace(url.origin, '').split('?')[0]);
}

function isStaticAsset(url) {
  return /\/assets\/(css|js|img)\//.test(url.pathname)
    || /manifest\.(webmanifest|php)$/.test(url.pathname);
}

function notifyClientsToSync() {
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
    clients.forEach(function (client) {
      try {
        client.postMessage({ type: 'SERI_POS_SYNC' });
      } catch (e) { /* ignore */ }
    });
  });
}

function clearSensitiveCaches() {
  return caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) {
      // Páginas HTML (CSRF/sesión) y cualquier cache de páginas previa.
      return k.indexOf('-pages') !== -1 || k === PAGE_CACHE;
    }).map(function (k) {
      return caches.delete(k);
    }));
  });
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

  if (url.origin !== self.location.origin) return;

  if (isApiRequest(url)) {
    return;
  }

  if (isStaticAsset(url)) {
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

  if ((req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') !== -1)
      && isCajaNavigation(url)) {
    event.respondWith(
      fetch(req).then(function (res) {
        // Solo cachear caja si la respuesta es OK y el usuario sigue autenticado
        // (evita cachear login/redirects).
        if (res && res.ok && res.url && res.url.indexOf('/login') === -1) {
          // Clonar ANTES de cache.put: el body solo se puede leer una vez.
          var pageCopy = res.clone();
          var shellCopy = res.clone();
          caches.open(PAGE_CACHE).then(function (cache) {
            return Promise.all([
              cache.put(req, pageCopy),
              cache.put('seri-caja-offline-shell', shellCopy)
            ]);
          }).catch(function () {});
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
  if (!event.data) return;
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data.type === 'SERI_POS_SYNC_NOW') {
    event.waitUntil(notifyClientsToSync());
  }
  if (event.data.type === 'SERI_CLEAR_SENSITIVE') {
    event.waitUntil(clearSensitiveCaches());
  }
});

self.addEventListener('sync', function (event) {
  if (event.tag === 'seri-pos-sync') {
    event.waitUntil(notifyClientsToSync());
  }
});

self.addEventListener('periodicsync', function (event) {
  if (event.tag === 'seri-pos-catalog') {
    event.waitUntil(notifyClientsToSync());
  }
});
