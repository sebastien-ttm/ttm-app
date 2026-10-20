/*
 * Service worker de l'appli TTM (PWA).
 *
 * Rôles :
 *  1. Notifications push web : affichage (« push ») et ouverture de l'appli au clic.
 *  2. Démarrage hors ligne : l'appli (coquille HTML + JS/CSS/polices/images) est conservée au
 *     moment de l'installation et à chaque visite ; sans réseau — ou si le réseau ne répond pas
 *     en NAV_TIMEOUT_MS — elle démarre depuis ce cache. Les DONNÉES (listes, planning…) sont, elles,
 *     conservées par l'appli elle-même (src/api/offlineCache.ts). Sans coquille en cache (toute
 *     première visite hors ligne), page « hors ligne ».
 *
 * Ce qu'il NE fait PAS : il ne met jamais en cache l'API (/api), l'administration (/admin) ni les
 * fichiers envoyés (/uploads). Le réseau passe toujours en premier pour la page : un nouveau
 * déploiement est vu tout de suite ; la coquille en cache ne sert qu'en secours.
 *
 * Les valeurs d'espace réservé de VERSION et de BUILD_FILES ci-dessous sont remplacées au build
 * (scripts/inject-pwa-meta.mjs) par la version du déploiement et la liste de ses fichiers : chaque
 * déploiement installe un nouveau service worker et vide les anciens caches.
 */
const VERSION = '__SW_VERSION__';
const CACHE = 'ttm-' + VERSION;
const OFFLINE_URL = '/offline.html';
// Clé (fictive) sous laquelle la coquille de l'appli est conservée : toutes les routes de l'appli
// servent le même index.html (expo.web.output = "single").
const SHELL_URL = '/__app-shell';
// Au-delà, le réseau est jugé indisponible (réseau « connecté mais sans internet ») et l'appli démarre
// depuis le cache plutôt que de rester figée sur l'écran de démarrage.
const NAV_TIMEOUT_MS = 4000;
// Les icônes portent un « ?v= » : leur nom ne change pas d'une version à l'autre, et un cache
// navigateur (ou ce cache) garderait sinon l'ancienne image (voir icons/README.md).
const ICON_URL = '/icons/icon-192.png?v=2';
const PRECACHE = [OFFLINE_URL, '/manifest.webmanifest', ICON_URL, '/icons/badge-96.png'];
// Fichiers du build (JS, polices, images) : liste injectée au build. Non remplacée (service worker
// servi tel quel en développement) : liste vide.
let BUILD_FILES = [];
try { BUILD_FILES = JSON.parse('__PRECACHE_FILES__'); } catch (e) { BUILD_FILES = []; }

/** Chemins servis par le backend ou à ne jamais intercepter. */
const BYPASS_PREFIXES = ['/api', '/admin', '/uploads', '/bundles', '/_wdt', '/_profiler', '/sw.js'];

/** Conserve la coquille de l'appli si la réponse est bien la page HTML. */
function cacheShell(response) {
  if (!response || !response.ok || !(response.headers.get('content-type') || '').includes('text/html')) return;
  const copy = response.clone();
  caches.open(CACHE).then((cache) => cache.put(SHELL_URL, copy)).catch(() => undefined);
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then(async (cache) => {
        await cache.addAll(PRECACHE);
        // Appli complète précachée : démarre hors ligne dès l'installation. Un fichier manquant ne doit pas
        // faire échouer l'installation (le service worker resterait en place avec l'ancienne version).
        await Promise.all(BUILD_FILES.map((file) => cache.add(file).catch(() => undefined)));
        await fetch('/', { cache: 'reload' }).then(cacheShell).catch(() => undefined);
      })
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

  // Pages : réseau d'abord (nouveau déploiement vu tout de suite), mais borné dans le temps ; sans réponse →
  // coquille de l'appli en cache, à défaut page « hors ligne ».
  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      const network = fetch(request);
      network.then(cacheShell).catch(() => undefined);
      try {
        return await Promise.race([
          network,
          new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), NAV_TIMEOUT_MS)),
        ]);
      } catch (e) {
        return (await caches.match(SHELL_URL)) || (await caches.match(OFFLINE_URL)) || Response.error();
      }
    })());
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
