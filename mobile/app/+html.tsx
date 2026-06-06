import { ScrollViewStyleReset } from 'expo-router/html';
import { type PropsWithChildren } from 'react';

/**
 * Wrapper HTML racine pour Expo Router (web export).
 * Permet d'injecter les balises <head> nécessaires à la PWA :
 *  - manifest.webmanifest        → « Ajouter à l'écran d'accueil » Android
 *  - apple-touch-icon            → icône iOS Safari sur l'écran d'accueil
 *  - apple-mobile-web-app-*      → mode standalone iOS + barre rouge
 *  - theme-color                 → couleur de la barre d'URL Android Chrome
 *  - favicon SVG                 → onglet navigateur (et fallback ico)
 */
export default function Root({ children }: PropsWithChildren) {
  return (
    <html lang="fr">
      <head>
        <meta charSet="utf-8" />
        <meta httpEquiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover" />

        <title>TTM — Triathlon Toulouse Métropole</title>
        <meta name="description" content="Application du club Triathlon Toulouse Métropole." />

        {/* === PWA === */}
        <link rel="manifest" href="/manifest.webmanifest" />
        <meta name="theme-color" content="#D32F2F" />

        {/* === iOS Safari : icône d'écran d'accueil + mode standalone === */}
        {/* iOS ignore le manifest pour l'icône — il faut un link dédié. */}
        <link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
        <meta name="apple-mobile-web-app-title" content="TTM" />

        {/* === Favicon (onglet navigateur) === */}
        <link rel="icon" type="image/svg+xml" href="/icons/icon.svg" />
        <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png" />
        <link rel="icon" type="image/png" sizes="16x16" href="/icons/favicon-16.png" />

        {/* Reset CSS recommandé par react-native-web */}
        <ScrollViewStyleReset />
      </head>
      <body>{children}</body>
    </html>
  );
}
