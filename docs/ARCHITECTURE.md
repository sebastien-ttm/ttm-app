# Architecture — TTM

Document de référence : schéma de données, contrat API, rôles, flux d'authentification, organisation du code.

## 1. Vue d'ensemble

```
┌────────────────────┐         ┌──────────────────────────────┐
│  Mobile (Expo RN)  │  HTTPS  │  Backend Symfony 7           │
│  iOS / Android     │ ──────► │  ├─ /api/*    (API Platform) │
│                    │   JWT   │  ├─ /admin/*  (EasyAdmin)    │
└────────────────────┘         │  └─ /         (Twig public)  │
                               └──────┬───────────────────────┘
                                      │
                                ┌─────▼──────┐
                                │  MariaDB   │
                                └────────────┘
                                      │
                              ┌───────▼────────┐
                              │  uploads/      │
                              │  ├─ articles/  │  (public)
                              │  └─ training/  │  (privé, contrôle auth)
                              └────────────────┘
```

## 2. Schéma de base de données

### `user`
Identité du licencié. Le numéro de licence est l'identifiant métier unique.

| Colonne | Type | Notes |
|---|---|---|
| `id` | INT, PK auto | |
| `num_licence` | VARCHAR(32), UNIQUE | identifiant métier (ex. "A12345C") |
| `nom` | VARCHAR(120) | |
| `prenom` | VARCHAR(120) | |
| `email` | VARCHAR(180), UNIQUE | utilisé pour magic link |
| `telephone` | VARCHAR(20), nullable | |
| `categorie` | ENUM('senior','jeune') | |
| `statut_licence` | VARCHAR(40) | "Actif", "Inactif", "Suspendu"… brut depuis CSV |
| `roles` | JSON | `["ROLE_USER"]`, `["ROLE_COACH"]`, `["ROLE_ADMIN"]` |
| `password` | VARCHAR(255), nullable | bcrypt, optionnel (sinon magic link only) |
| `is_active` | BOOL | dérivé de `statut_licence` à l'import |
| `last_csv_sync_at` | DATETIME, nullable | trace du dernier import qui a touché le compte |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

### `magic_link_token`
Tokens à usage unique pour la connexion par e-mail.

| Colonne | Type | Notes |
|---|---|---|
| `id` | INT, PK | |
| `user_id` | INT, FK user | |
| `token_hash` | VARCHAR(255) | SHA-256 du token (jamais stocké en clair) |
| `expires_at` | DATETIME | TTL 15 min par défaut |
| `used_at` | DATETIME, nullable | usage unique |

### `article`
Publication sur le mur d'actualités.

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `title` | VARCHAR(200) |
| `content` | TEXT (HTML structuré, sanitizé) |
| `author_id` | INT, FK user |
| `published_at` | DATETIME, nullable (brouillon si null) |
| `created_at`, `updated_at` | DATETIME |

### `article_photo`
0..n photos par article.

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `article_id` | INT, FK article (CASCADE) |
| `file_path` | VARCHAR(255) |
| `position` | INT |
| `alt` | VARCHAR(255) |

### `reaction`
Émoticons sur articles. Un user peut poser plusieurs émoticons distincts mais pas le même 2x.

| Colonne | Type | Notes |
|---|---|---|
| `id` | INT, PK | |
| `article_id` | INT, FK article (CASCADE) | |
| `user_id` | INT, FK user (CASCADE) | |
| `emoji` | VARCHAR(8) | `"👍"`, `"❤️"`, `"🔥"`, `"😂"`, `"😮"` |
| `created_at` | DATETIME | |
| | UNIQUE(article_id, user_id, emoji) | |

### `comment`
| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `article_id` | INT, FK article (CASCADE) |
| `user_id` | INT, FK user |
| `content` | TEXT |
| `created_at` | DATETIME |

### `training_plan`
Plan d'entraînement hebdomadaire posté par un entraîneur.

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `title` | VARCHAR(200) |
| `description` | TEXT, nullable |
| `file_path` | VARCHAR(255) (PDF, dans var/uploads/training/) |
| `posted_by_id` | INT, FK user |
| `week_starts_at` | DATE, nullable (lundi de la semaine concernée) |
| `posted_at` | DATETIME |

### `static_page`
Pages éditoriales pérennes (Bureau, Lieux de RDV, Partenaires…).

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `slug` | VARCHAR(120), UNIQUE |
| `title` | VARCHAR(200) |
| `content` | TEXT (HTML) |
| `is_published` | BOOL |
| `updated_at` | DATETIME |

### `menu_item`
Configuration des onglets du menu mobile.

| Colonne | Type | Notes |
|---|---|---|
| `id` | INT, PK | |
| `label` | VARCHAR(60) | |
| `type` | ENUM | `feed`, `training`, `calendar`, `page`, `external` |
| `target` | VARCHAR(255), nullable | slug de page ou URL externe |
| `icon` | VARCHAR(60), nullable | nom d'icône (Material/Ionicons) |
| `position` | INT | ordre d'affichage |
| `is_visible` | BOOL | |

### `event`
Calendrier club.

| Colonne | Type | Notes |
|---|---|---|
| `id` | INT, PK | |
| `title` | VARCHAR(200) | |
| `description` | TEXT, nullable | |
| `location` | VARCHAR(255), nullable | |
| `starts_at` | DATETIME | |
| `ends_at` | DATETIME, nullable | |
| `type` | ENUM | `course`, `stage`, `entrainement`, `social` |
| `color` | VARCHAR(7), nullable | hex `#RRGGBB` pour affichage |

### `device_token`
Tokens push Expo, par utilisateur (un user peut avoir plusieurs devices).

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `user_id` | INT, FK user (CASCADE) |
| `expo_push_token` | VARCHAR(255), UNIQUE |
| `platform` | ENUM('ios','android') |
| `last_seen_at` | DATETIME |

### `banner`
Bannière de tête (visuel saison, événement). Une seule active à la fois en pratique.

| Colonne | Type |
|---|---|
| `id` | INT, PK |
| `image_path` | VARCHAR(255) |
| `title` | VARCHAR(200), nullable |
| `link_url` | VARCHAR(500), nullable |
| `starts_at`, `ends_at` | DATETIME, nullable (fenêtre d'activation) |
| `is_active` | BOOL |

## 3. Rôles & permissions

| Action | Adhérent | Entraîneur | Admin |
|---|---|---|---|
| Lire articles, pages, calendrier, plans | ✓ | ✓ | ✓ |
| Réagir, commenter | ✓ | ✓ | ✓ |
| Publier plan d'entraînement (PDF) | ✗ | ✓ | ✓ |
| Modifier ses propres commentaires | ✓ | ✓ | ✓ |
| Modérer commentaires d'autrui | ✗ | ✗ | ✓ |
| Publier articles | ✗ | ✗ | ✓ |
| CRUD pages statiques | ✗ | ✗ | ✓ |
| Configurer menu, événements, bannière | ✗ | ✗ | ✓ |
| Importer CSV adhérents | ✗ | ✗ | ✓ |

Hiérarchie : `ROLE_ADMIN` > `ROLE_COACH` > `ROLE_USER`. Configurée via `role_hierarchy` dans `security.yaml`.

## 4. Authentification

### Flux Magic Link
```
Mobile/Web ──(POST /api/auth/magic-link/request {email})──► Backend
Backend : génère token, hash, stocke (TTL 15min), envoie e-mail avec lien
Backend ◄──(GET /api/auth/magic-link/verify?token=xxx)── Email
Backend : vérifie, marque used_at, retourne JWT
```

### Flux mot de passe
```
Mobile/Web ──(POST /api/auth/login {email, password})──► Backend
Backend : check Argon2/bcrypt, retourne JWT
```

### JWT
- TTL access token : 1h
- Refresh token : 30j (table `refresh_token`, géré par `gesdinet/jwt-refresh-token-bundle`)
- Header : `Authorization: Bearer <jwt>`

### Définir / changer son mot de passe
- Adhérent connecté via magic link peut poser un mot de passe : `POST /api/me/password { new_password }`.
- Reset via magic link à nouveau (= "mot de passe oublié").

## 5. Contrat API (résumé)

Toutes les routes sont préfixées par `/api`. Auth requise sauf mention contraire.

### Auth (publique)
- `POST /auth/magic-link/request` — body `{email}` → `204`
- `GET /auth/magic-link/verify?token=` → `{access_token, refresh_token, user}`
- `POST /auth/login` — body `{email, password}` → `{access_token, refresh_token, user}`
- `POST /auth/refresh` — body `{refresh_token}` → `{access_token}`

### Profil
- `GET /me` → user courant
- `POST /me/password` — body `{new_password}`
- `POST /me/devices` — body `{expo_push_token, platform}`
- `DELETE /me/devices/{token}`

### Articles (lecture : tous ; écriture : ADMIN)
- `GET /articles?page=1&limit=20` (paginé, ordre `published_at` desc)
- `GET /articles/{id}` (inclut photos, compteurs réactions, derniers commentaires)
- `GET /articles/{id}/comments?page=`
- `POST /articles/{id}/comments` — body `{content}` → `201`
- `PUT /articles/{id}/reactions` — body `{emoji}` (toggle ; ajoute si absent, supprime si présent)

### Plans d'entraînement (lecture : tous ; écriture : COACH+)
- `GET /training-plans?page=`
- `GET /training-plans/{id}`
- `GET /training-plans/{id}/file` — stream PDF (Content-Disposition: inline) avec auth

### Pages statiques
- `GET /pages` — liste (slug + title)
- `GET /pages/{slug}` — contenu

### Menu & calendrier
- `GET /menu` — items visibles, triés par position
- `GET /events?from=YYYY-MM-DD&to=YYYY-MM-DD`

### Bannière
- `GET /banner/active` — bannière active actuelle (publique : pas d'auth)

## 6. Stockage fichiers

Chemins **relatifs au dossier `backend/`** :

| Type | Chemin | Servi comment |
|---|---|---|
| Photos articles | `public/uploads/articles/{yyyy}/{mm}/{filename}` | Apache direct (cache HTTP long) |
| Bannières | `public/uploads/banners/{filename}` | Apache direct |
| PDF plans | `var/uploads/training/{yyyy}/{filename}` | Controller Symfony (auth check) |

Limites :
- Photos : 5 Mo, formats `jpg/jpeg/png/webp`
- PDF : 10 Mo, format `pdf` strict (validation MIME)

Convention des noms : `{uuid}.{ext}` à la création (jamais le nom d'origine, pour éviter collisions et XSS).

## 7. Import CSV

### Colonnes attendues (en-tête obligatoire)
```
num_licence,nom,prenom,email,telephone,categorie,statut_licence
```

### Algorithme
1. Parse via `league/csv`, séparateur `,` (configurable), encodage UTF-8 (BOM toléré).
2. Pour chaque ligne :
   - Cherche user par `num_licence`.
   - **Existe** : update champs, set `last_csv_sync_at = NOW()`, `is_active` selon `statut_licence`.
   - **N'existe pas** : crée le user (rôle `ROLE_USER`, password null), envoie e-mail de bienvenue avec magic link.
3. Après le passage de toutes les lignes : tout user dont `last_csv_sync_at` < timestamp de cet import → `is_active = false` ("désactivation"). Ne supprime jamais.
4. Compte-rendu affiché en EasyAdmin : créés / mis à jour / désactivés / erreurs ligne par ligne.

### Mapping `statut_licence` → `is_active`
- "Actif", "ACTIF" → `true`
- toute autre valeur → `false`

## 8. Notifications push

- Tokens enregistrés à la connexion mobile (`POST /me/devices`).
- À la création d'un `training_plan` : Symfony pousse un message vers tous les `device_token` actifs via l'**Expo Push API** (`https://exp.host/--/api/v2/push/send`), par lots de 100.
- Job asynchrone via Symfony Messenger (transport `doctrine` — pas besoin de Redis sur mutualisé).
- Idem pour articles avec un flag `notify_on_publish` (admin-driven).

## 9. Sécurité — points spécifiques

- HTTPS-only en prod (forcé par `.htaccess`).
- Cookies admin : `Secure`, `HttpOnly`, `SameSite=Lax`.
- CORS : whitelist `app://localhost` + domaine prod.
- Rate-limit `magic-link/request` : 3/min/IP, 5/h/email.
- Sanitize HTML articles via `HTMLPurifier` (bundle `mck89/htmlpurifier`).
- Validation MIME stricte sur uploads (pas que l'extension).
- Tokens magic link hashés en SHA-256 en base ; le clair n'existe que dans l'e-mail.

## 10. Décisions prises (et pourquoi)

| Sujet | Choix | Pourquoi |
|---|---|---|
| Pas de Redis ni Mailpit en prod | Doctrine pour cache + Messenger, SMTP O2Switch direct | Mutualisé : pas de service long. |
| Pas de Docker pour la dev | Laragon | Mime O2Switch (Apache + PHP-FPM + MariaDB), zéro friction Windows. |
| Symfony 7 + API Platform vs Laravel | Symfony | Sécurité builtin (firewall, voters), API Platform autoréalise CRUD doctriné, écosystème plus mûr pour back-office strict. |
| EasyAdmin vs Sonata | EasyAdmin 4 | Plus léger, plus rapide à mettre en main, suffisant pour ce besoin. |
| RN Expo vs RN bare | Expo | Push, OTA, EAS Build. Sole maintainer → pas de gestion native iOS/Android au quotidien. |
| Mode hors-ligne | Pas dans v1 | Cache simple via React Query stale-while-revalidate. À itérer si besoin. |

## 11. Hors périmètre v1

Pour rester focus, **explicitement non inclus** dans la première version :
- Inscription publique (les comptes naissent du CSV).
- Albums photos / galeries dédiées.
- Messagerie 1:1 entre adhérents.
- Paiement / cotisations en ligne.
- Statistiques entraînement / Strava.

À discuter en v2 selon retours utilisateurs.
