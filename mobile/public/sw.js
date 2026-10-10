/*
 * Service worker de l'appli TTM (PWA).
 *
 * Rôles :
 *  1. Notifications push web : affichage (« push ») et ouverture de l'appli au clic.
 *  2. Mode hors ligne minimal : page « hors ligne » si le réseau est indisponible,
 *     et cache des fichiers de l'appli (JS/CSS/images versionnés) pour la rapidité.
 *
 * Ce qu'il NE fait PAS : il ne met jamais en cache l'API (/api), l'administration
 * (/admin), les fichiers envoyés (/uploads) ni les pages HTML (le réseau passe
 * toujours en premier : un nouveau déploiement est vu tout de suite).
 *
 * La constante VERSION ci-dessous est remplacée au build (scripts/inject-pwa-meta.mjs)
 * par la version du déploiement : chaque déploiement installe un nouveau service
 * worker et vide les anciens caches.
 */
const VERSION = '__SW_VERSION__';
const CACHE = 'ttm-' + VERSION;
const OFFLINE_URL = '/offline.html';
// Les icônes portent un « ?v= » : leur nom ne change pas d'une version à l'autre, et un cache
// navigateur (ou ce cache) garderait sinon l'ancienne image (voir icons/README.md).
const ICON_URL = '/icons/icon-192.png?v=2';
const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest', ICON_URL, '/icons/badge-96.png'];

/** Chemins servis par le backend ou à ne jamais intercepter. */
const BYPASS_PREFIXES = ['/api', '/admin', '/uploads', '/bundles', '/_wdt', '/_profiler', '/sw.js'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('ttm-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

function isBypassed(url) {
  return url.origin !== self.location.origin
    || BYPASS_PREFIXES.some((p) => url.pathname === p || url.pathname.startsWith(p + '/'));
}

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (isBypassed(url)) return;

  // Pages : réseau d'abord ; hors ligne → page dédiée.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match(OFFLINE_URL)),
    );
    return;
  }

  // Fichiers de l'appli (hachés, donc immuables) : cache d'abord.
  if (url.pathname.startsWith('/_expo/static/')) {
    event.respondWith(
      caches.match(request).then((hit) => hit || fetch(request).then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      })),
    );
    return;
  }

  // Images et polices de l'appli : cache d'abord, rafraîchi en arrière-plan.
  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/icons/')) {
    event.respondWith(
      caches.match(request).then((hit) => {
        const refresh = fetch(request).then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return response;
        }).catch(() => hit);
        return hit || refresh;
      }),
    );
  }
});

/* ---------------------------------------------------------------- Push */

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }

  const options = {
    body: data.body || '',
    icon: data.icon || ICON_URL,
    // Petit pictogramme de la barre d'état Android : seule sa transparence compte (silhouette
    // blanche du logo, voir icons/README.md) — une icône pleine donnerait un carré blanc.
    badge: '/icons/badge-96.png',
    data: { url: data.url || '/' },
  };
  if (data.tag) {
    // Même tag = la nouvelle notification remplace l'ancienne (et re-sonne).
    options.tag = data.tag;
    options.renotify = true;
  }

  event.waitUntil(self.registration.showNotification(data.title || 'TTM', options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin).href;

  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
        await client.focus();
        if ('navigate' in client) {
          try { await client.navigate(target); } catch (e) { /* navigation refusée : l'appli reste ouverte */ }
        }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});
