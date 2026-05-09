# TTM — Plateforme Triathlon Toulouse Métropole

Écosystème numérique privé pour le club Triathlon Toulouse Métropole : application mobile native (iOS / Android) pour les adhérents et back-office web pour le bureau et les entraîneurs.

## Stack

| Brique | Choix | Version cible |
|---|---|---|
| Backend API | **Symfony** + API Platform | 7.1 |
| Admin web | **EasyAdmin** (intégré au backend) | 4.x |
| Base de données | **MariaDB** | 10.6+ |
| Mobile | **React Native** + Expo (TypeScript) | SDK 51+ |
| Auth | JWT (Lexik) + Magic Link (Symfony LoginLink) | — |
| Push | Expo Push API + APNS/FCM en relais | — |
| Hébergement cible | O2Switch mutualisé "Cpanel Unique" | — |

## Structure du dépôt

```
ttm-app/
├── README.md                      # ce fichier
├── docs/
│   ├── ARCHITECTURE.md            # design : schéma DB, API, rôles
│   ├── SETUP-DEV.md               # mise en route Windows + Laragon
│   └── DEPLOYMENT-O2SWITCH.md     # déploiement O2Switch pas à pas
├── backend/                       # Symfony 7 + EasyAdmin + API
│   ├── composer.json
│   ├── public/
│   ├── src/
│   ├── config/
│   └── migrations/
└── mobile/                        # Expo React Native
    ├── package.json
    ├── app.json
    └── src/
```

## Démarrage rapide

1. **Installez les prérequis** — voir [docs/SETUP-DEV.md](docs/SETUP-DEV.md).
2. **Backend** :
   ```powershell
   cd backend
   composer install
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate
   php bin/console app:fixtures:demo  # données de démo
   symfony serve  # ou Laragon → http://ttm-app.test
   ```
3. **Mobile** :
   ```powershell
   cd mobile
   npm install
   npx expo start
   ```

Back-office : http://ttm-app.test/admin
API : http://ttm-app.test/api

## Rôles

| Rôle | Permissions |
|---|---|
| `ROLE_USER` (Adhérent) | Consulter, réagir, commenter |
| `ROLE_COACH` (Entraîneur) | + Publier des plans d'entraînement (PDF) |
| `ROLE_ADMIN` | Tout : import CSV, articles, pages, menu, événements, bannière |

## Statut

🚧 En cours de développement initial. Voir [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) pour le périmètre détaillé.
