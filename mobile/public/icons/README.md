# Icônes PWA

Ce dossier contient les icônes utilisées pour :
- l'onglet du navigateur (favicon)
- l'icône « Ajouter à l'écran d'accueil » sur smartphone (Android Chrome + iOS Safari)
- l'écran de démarrage (splash) Android d'une PWA installée : icône du manifeste centrée sur `background_color`
- la grande icône des notifications push

Toutes reprennent l'**emblème officiel du club** (`assets/branding/logo.svg`, rouge `#E32636`).

`icon.svg` (source) est commité : l'emblème **retracé en vectoriel** (le logo officiel n'est qu'une image
de 100×125 px, trop petite pour des icônes nettes de 512 px) sur fond blanc. C'est la source des icônes
PNG ci-dessous : emblème centré sur toute la hauteur à 70 % pour les icônes « any » et l'icône iOS, à 56 %
pour l'icône « maskable » (zone sûre des icônes adaptatives Android, qui découpent un cercle ou un carré arrondi).

## Fichiers (commités)

| Fichier | Taille | Usage |
|---------|--------|-------|
| `icon.svg` | vectoriel | Source des icônes PNG ; icône « any » du manifeste |
| `favicon-16.png` | 16×16 | Favicon onglet : emblème du club sur fond transparent |
| `favicon-32.png` | 32×32 | Favicon onglet (la plupart des navigateurs), emblème du club |
| `../favicon.ico` | 16/32/48 | Favicon à la racine du site : demandé par défaut par les navigateurs et par l'administration (EasyAdmin) — emblème du club |
| `apple-touch-icon.png` | 180×180 | iOS Safari « Ajouter à l'écran d'accueil » (fond opaque obligatoire) |
| `icon-192.png` | 192×192 | Android Chrome icône PWA standard + grande icône des notifications |
| `icon-512.png` | 512×512 | Android Chrome splash + écran d'accueil HD |
| `icon-maskable-512.png` | 512×512 | Android adaptative icon (emblème réduit pour rester dans la zone sûre) |
| `badge-96.png` | 96×96 | Petit pictogramme des notifications push (Android) : silhouette blanche du logo du club sur fond transparent. Android n'en garde que la transparence (voir ci-dessous) |

## Comment les régénérer

**Option locale — ImageMagick** (à partir de `icon.svg`, emblème à 70 %) :

```bash
cd mobile/public/icons
magick icon.svg -resize 180x180 apple-touch-icon.png
magick icon.svg -resize 192x192 icon-192.png
magick icon.svg -resize 512x512 icon-512.png
magick icon.svg -resize 410x410 -gravity center -background "#FFFFFF" -extent 512x512 icon-maskable-512.png
```

Le dernier réduit à 80 % (soit un emblème à 56 %) avec marge blanche : zone sûre des icônes adaptatives Android.

Si le logo change : retracer l'emblème (contour d'après le canvas du logo, simplifié) pour refaire `icon.svg`,
puis relancer les commandes ci-dessus.

## Favicons (onglet du navigateur)

Les favicons (`favicon-16.png`, `favicon-32.png`, `../favicon.ico`) sont l'**emblème du club** (rouge, fond
transparent, ~6 % de marge) tiré de `mobile/assets/branding/logo-mark.png`, lui-même issu du logo officiel
`assets/branding/logo.svg`. Ils sont volontairement sur fond transparent (lisibles dans un onglet clair
comme sombre) ; c'est pourquoi `icon.svg`, à fond blanc, n'est pas déclaré comme favicon. Le `.ico` contient
les PNG 16, 32 et 48 px.

## Pictogramme des notifications (`badge-96.png`)

Android affiche ce pictogramme dans la barre d'état en ne gardant que son **canal alpha** : tout pixel
opaque devient blanc. Une icône carrée pleine (comme `icon-192.png`) donne donc un carré blanc.
`badge-96.png` est la silhouette du logo officiel (`assets/branding/logo.svg`) : pixels blancs là où
le logo est rouge, transparents ailleurs, emblème recadré et centré avec ~6 px de marge. Pour le
régénérer (changement de logo) : dessiner le logo sur un canvas blanc, passer chaque pixel en blanc
avec pour alpha `(255 − vert) / 190` (borné à 0–1), puis exporter en PNG 96×96.

## Cache des icônes et « ?v= »

Les icônes gardent le même nom d'une version à l'autre. Or le serveur mettait les `.png` en cache **un an**
(`.htaccess`) : un téléphone ou un navigateur qui avait déjà vu `icon-192.png` le gardait, même après
réinstallation de l'appli, et affichait donc l'ancienne icône. Deux protections :

- le `.htaccess` ne cache plus les icônes (`icon*.png`, `apple-touch-icon.png`, `favicon*`) qu'**un jour** ;
- leurs adresses portent un `?v=2` (manifeste, balises du `<head>` injectées par `scripts/inject-pwa-meta.mjs`,
  service worker `public/sw.js`, page `offline.html`, gabarit backend `base.html.twig`, notification push
  `SendWebPushMessageHandler.php`). **Après un changement d'icônes, incrémenter ce `?v=`** (rechercher `v=2`
  dans le dépôt) pour que tous les appareils les rechargent sans attendre.

## Vérification après déploiement

Une icône d'appli **déjà installée** ne change pas toute seule tout de suite : Android ne met à jour
l'icône (et l'écran de démarrage) qu'à la prochaine mise à jour de l'appli installée, parfois après un
jour. Pour voir le changement immédiatement : désinstaller l'appli puis la réinstaller (bouton
« Installer l'application » du profil). Sur iPhone/iPad : supprimer l'icône de l'écran d'accueil et
l'ajouter de nouveau.

Sur mobile :
- **Android Chrome** : ouvre l'app, menu (⋮) → « Installer l'application » ou « Ajouter à l'écran d'accueil ».
  Tu dois voir l'emblème du club et le nom « TTM ».
- **iOS Safari** : ouvre l'app, bouton partage → « Sur l'écran d'accueil ».
  L'emblème du club apparaît dans l'aperçu avant ajout.

Sur desktop :
- Ouvre les DevTools → Application → Manifest → toutes les icônes doivent charger
- Chrome propose l'install (icône ⊕ dans la barre d'URL)
