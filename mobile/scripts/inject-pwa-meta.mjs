#!/usr/bin/env node
/**
 * Post-build : injecte dans dist/index.html les balises <head> nécessaires
 * à la PWA. Pourquoi un script externe au lieu de app/+html.tsx ?
 * → en mode `expo.web.output: "single"` (notre cas), Expo Router IGNORE
 *   app/+html.tsx et émet son template par défaut. Le seul moyen fiable
 *   d'injecter du contenu dans <head> est de patcher dist/index.html
 *   après l'export.
 *
 * Idempotent : si les tags sont déjà présents, on ne rien fait.
 *
 * Usage : node scripts/inject-pwa-meta.mjs
 * Ou via npm script : `npm run build:web` (chaîné après expo export).
 */

import { execSync } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const DIST_DIR = join(process.cwd(), 'dist');
const INDEX = join(DIST_DIR, 'index.html');
const HTACCESS = join(DIST_DIR, '.htaccess');
const VERSION_JSON = join(DIST_DIR, 'version.json');

/**
 * Version du build : SHA git court + timestamp. Utilisé par le
 * WebUpdateGate côté client pour détecter qu'un nouveau bundle a été
 * déployé et proposer un reload à l'user sans attendre qu'il ferme
 * son onglet. Le SHA seul suffirait, mais le timestamp aide au debug.
 */
function computeVersion() {
  let sha = 'dev';
  try {
    sha = execSync('git rev-parse --short HEAD', { encoding: 'utf8' }).trim();
  } catch { /* pas dans un repo git → fallback 'dev' */ }
  return { sha, builtAt: new Date().toISOString() };
}

const SENTINEL = 'data-pwa-injected="ttm"';

const HEAD_INJECTION = `
    <!-- ${SENTINEL} : injecté par scripts/inject-pwa-meta.mjs -->
    <meta name="description" content="Application du club Triathlon Toulouse Métropole." />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover" />

    <!-- Open Graph / Twitter — mis à jour dynamiquement par
         useDocumentTitle() sur les pages de détail (article, event,
         page statique, sondage). Les valeurs par défaut ci-dessous
         restent affichées pour les URLs sans page-title (racine…). -->
    <meta property="og:title" content="TTM — Triathlon Toulouse Métropole" />
    <meta property="og:description" content="Application du club Triathlon Toulouse Métropole." />
    <meta property="og:type" content="website" />
    <meta name="twitter:card" content="summary" />
    <meta name="twitter:title" content="TTM — Triathlon Toulouse Métropole" />
    <meta name="twitter:description" content="Application du club Triathlon Toulouse Métropole." />

    <!-- Version embarquée dans le bundle courant. Le WebUpdateGate
         compare cette valeur au /version.json récent pour détecter
         un déploiement sans passer par un rebuild. -->
    <meta name="app-version" content="__APP_VERSION__" />

    <!-- === PWA (Android Chrome) === -->
    <link rel="manifest" href="/manifest.webmanifest" />
    <meta name="theme-color" content="#D32F2F" />

    <!-- === iOS Safari : icône d'écran d'accueil + mode standalone === -->
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="TTM" />

    <!-- === Favicon (onglet navigateur) : emblème du club ===
         Pas de favicon SVG : icon.svg a un fond blanc plein, alors que les PNG transparents
         restent lisibles dans un onglet clair comme sombre. -->
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png" />
    <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16.png" />
    <!-- /pwa-injected -->`;

function fail(msg) {
  console.error(`❌ inject-pwa-meta: ${msg}`);
  process.exit(1);
}

function info(msg) {
  console.log(`✓ inject-pwa-meta: ${msg}`);
}

if (!existsSync(INDEX)) {
  fail(`dist/index.html introuvable. Lance d'abord 'npx expo export --platform web'.`);
}

const version = computeVersion();
writeFileSync(VERSION_JSON, JSON.stringify(version, null, 2) + '\n', 'utf8');
info(`dist/version.json généré (${version.sha}).`);

// Service worker : sa version = celle du déploiement. Un sw.js différent à chaque
// déploiement fait installer le nouveau service worker, qui purge les anciens
// caches (voir public/sw.js). Fait AVANT le garde-fou d'idempotence plus bas.
const SW_FILE = join(DIST_DIR, 'sw.js');
if (existsSync(SW_FILE)) {
  const sw = readFileSync(SW_FILE, 'utf8');
  writeFileSync(SW_FILE, sw.replaceAll('__SW_VERSION__', version.sha), 'utf8');
  info(`dist/sw.js versionné (${version.sha}).`);
} else {
  info('dist/sw.js absent : service worker non publié (vérifier mobile/public/sw.js).');
}

// Setup O2Switch : mobile + backend Symfony partagent le même dossier
// (public_html/ttm-app/backend/public/ = document root du sous-domaine
// app.*). Le .htaccess Symfony orchestre TOUT le routage (redirect
// HTTPS, passthrough Authorization JWT, /admin + /api → index.php,
// SPA fallback pour le reste). Si un .htaccess mobile se retrouve dans
// dist/, l'upload l'écrase silencieusement et casse le backend.
// On purge donc ici pour être safe même si un ancien build en laisse un.
if (existsSync(HTACCESS)) {
  unlinkSync(HTACCESS);
  info('dist/.htaccess supprimé (le .htaccess Symfony backend gère le routage).');
}

let html = readFileSync(INDEX, 'utf8');

if (html.includes(SENTINEL)) {
  info('Meta-tags déjà présents (idempotent), rien à faire.');
  process.exit(0);
}

// 1) lang="en" → lang="fr"
html = html.replace(/<html lang="en">/, '<html lang="fr">');

// 2) Titre plus parlant qu'« TTM » seul
html = html.replace(/<title>TTM<\/title>/, '<title>TTM — Triathlon Toulouse Métropole</title>');

// 3) Remplace le viewport par défaut par celui qui supporte le notch iOS
html = html.replace(
  /<meta name="viewport"[^>]*\/?>\s*\n/,
  '',
);

// 4) Injection avant </head>
if (!html.includes('</head>')) {
  fail('Pas de </head> trouvé dans dist/index.html.');
}
const injection = HEAD_INJECTION.replace('__APP_VERSION__', version.sha);
html = html.replace('</head>', `${injection}\n  </head>`);

writeFileSync(INDEX, html, 'utf8');
info(`Meta-tags PWA injectés dans ${INDEX}`);
