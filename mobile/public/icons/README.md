# Icônes PWA

Ce dossier contient les icônes utilisées pour :
- l'onglet du navigateur (favicon)
- l'icône « Ajouter à l'écran d'accueil » sur smartphone (Android Chrome + iOS Safari)
- la splash screen Android d'une PWA installée

`icon.svg` (source) est commité — c'est le logo TTM officiel reconstitué en SVG (fond rouge club + monogramme blanc + bandeau bleu inférieur). Identique au logo de la page de login.

## Fichiers (commités)

Les PNG ci-dessous sont **dans le dépôt** (générés depuis `icon.svg`, rendu Arial gras
du navigateur). Si le logo change, régénérez-les à partir de `icon.svg` avec l'une des
options suivantes.

| Fichier | Taille | Usage |
|---------|--------|-------|
| `icon.svg` | vectoriel | Favicon SVG (navigateurs modernes), source pour générer les PNG |
| `favicon-16.png` | 16×16 | Favicon onglet (Firefox, anciens navigateurs) |
| `favicon-32.png` | 32×32 | Favicon onglet (la plupart des navigateurs) |
| `apple-touch-icon.png` | 180×180 | iOS Safari « Ajouter à l'écran d'accueil » |
| `icon-192.png` | 192×192 | Android Chrome icône PWA standard |
| `icon-512.png` | 512×512 | Android Chrome splash + écran d'accueil HD |
| `icon-maskable-512.png` | 512×512 | Android adaptative icon (avec marge intérieure ~10 %) |
| `badge-96.png` | 96×96 | Petit pictogramme des notifications push (Android) : silhouette blanche du logo du club sur fond transparent. Android n'en garde que la transparence (voir ci-dessous) |

## Comment les générer

**Option recommandée — outil web tout-en-un** :

1. Va sur https://realfavicongenerator.net/
2. Upload `icon.svg` (ou un PNG 512×512 généré depuis le SVG)
3. Configure :
   - iOS : marge à 0, background `#D32F2F`
   - Android : « Adaptive icon » → background `#D32F2F`, padding 10 %
   - Web App Manifest : nom « Triathlon Toulouse Métropole », short name « TTM », theme `#D32F2F`, background `#0d2148`
4. Télécharge le zip et place les fichiers ci-dessus dans ce dossier
   (le `manifest.json` est déjà dans `mobile/public/manifest.webmanifest`, ne pas remplacer)

**Option locale — ImageMagick** :

```bash
cd mobile/public/icons
magick icon.svg -resize 16x16   favicon-16.png
magick icon.svg -resize 32x32   favicon-32.png
magick icon.svg -resize 180x180 apple-touch-icon.png
magick icon.svg -resize 192x192 icon-192.png
magick icon.svg -resize 512x512 icon-512.png
magick icon.svg -resize 410x410 -gravity center -background "#D32F2F" -extent 512x512 icon-maskable-512.png
```

Le dernier ajoute une marge intérieure de ~10 % pour respecter la safe area des
icônes adaptatives Android.

## Pictogramme des notifications (`badge-96.png`)

Android affiche ce pictogramme dans la barre d'état en ne gardant que son **canal alpha** : tout pixel
opaque devient blanc. Une icône carrée pleine (comme `icon-192.png`) donne donc un carré blanc.
`badge-96.png` est la silhouette du logo officiel (`assets/branding/logo.svg`) : pixels blancs là où
le logo est rouge, transparents ailleurs, emblème recadré et centré avec ~6 px de marge. Pour le
régénérer (changement de logo) : dessiner le logo sur un canvas blanc, passer chaque pixel en blanc
avec pour alpha `(255 − vert) / 190` (borné à 0–1), puis exporter en PNG 96×96.

## Vérification après déploiement

Sur mobile :
- **Android Chrome** : ouvre l'app, menu (⋮) → « Ajouter à l'écran d'accueil ».
  Tu dois voir l'icône TTM et le nom « TTM ».
- **iOS Safari** : ouvre l'app, bouton partage → « Sur l'écran d'accueil ».
  L'icône TTM apparaît dans l'aperçu avant ajout.

Sur desktop :
- Ouvre les DevTools → Application → Manifest → toutes les icônes doivent charger
- Chrome propose l'install (icône ⊕ dans la barre d'URL)
