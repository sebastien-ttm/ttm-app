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

import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const DIST_DIR = join(process.cwd(), 'dist');
const INDEX = join(DIST_DIR, 'index.html');
const HTACCESS = join(DIST_DIR, '.htaccess');

const SENTINEL = 'data-pwa-injected="ttm"';

const HEAD_INJECTION = `
    <!-- ${SENTINEL} : injecté par scripts/inject-pwa-meta.mjs -->
    <meta name="description" content="Application du club Triathlon Toulouse Métropole." />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover" />

    <!-- === PWA (Android Chrome) === -->
    <link rel="manifest" href="/manifest.webmanifest" />
    <meta name="theme-color" content="#D32F2F" />

    <!-- === iOS Safari : icône d'écran d'accueil + mode standalone === -->
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="TTM" />

    <!-- === Favicon (onglet navigateur) === -->
    <link rel="icon" type="image/svg+xml" href="/icons/icon.svg" />
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

// Sécurité : si un vieux build a copié .htaccess depuis public/ vers dist/,
// on le supprime. Sur O2Switch on déploie dist/ dans backend/public/ et le
// .htaccess de Symfony (HTTPS, /admin, /api, etc.) doit primer — un
// .htaccess concurrent l'écraserait silencieusement.
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
html = html.replace('</head>', `${HEAD_INJECTION}\n  </head>`);

writeFileSync(INDEX, html, 'utf8');
info(`Meta-tags PWA injectés dans ${INDEX}`);
