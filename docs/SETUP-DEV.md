# Mise en route — environnement de développement Windows

> **TL;DR (déjà installé)** : ouvrir un PowerShell, lancer `.\scripts\dev-start.ps1` → PATH configuré, MySQL démarré, OpenSSL OK. Puis `cd backend; symfony serve` (ou laisser Apache via Laragon).
>
> **Gotchas Windows découverts** :
> - Laragon installe **MySQL 8.4** (pas MariaDB) → `serverVersion=8.4.3` dans `.env.local` en local. Pour O2Switch (mariadb-10.6) ce sera dans `.env.local` du serveur de prod.
> - PHP CLI ne charge aucun `php.ini` par défaut. Il faut en créer un dans `D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.ini` qui active openssl, intl, mbstring, sodium, gd, pdo_mysql, etc.
> - `lexik:jwt:generate-keypair` plante sur Windows si `OPENSSL_CONF` n'est pas exporté (`extras\ssl\openssl.cnf` dans le PHP de Laragon).
> - Laragon CLI (`laragon.exe start`) n'est pas fiable : utiliser le bouton "Démarrer tout" dans la GUI, ou le script `dev-start.ps1` qui lance directement `mysqld`.

## 1. Prérequis à installer

### Backend (Symfony / PHP)

**Laragon Full** — installeur unique : https://laragon.org/download/

> Téléchargez "Laragon — Full". Inclut Apache, Nginx, PHP 8.3, MariaDB 10.x, Composer, NodeJS, Git. C'est le moyen le plus rapide d'avoir un environnement qui mime O2Switch.

Après installation :

1. Lancez **Laragon** (raccourci bureau).
2. Vérifiez la version PHP : menu *Laragon → PHP → Version → 8.3* (ou plus récent).
3. Activez les extensions PHP requises : menu *Laragon → PHP → Extensions* → cochez :
   - `pdo_mysql` ✅
   - `intl` ✅
   - `mbstring` ✅
   - `openssl` ✅
   - `zip` ✅
   - `gd` ✅ (pour les uploads d'images)
   - `fileinfo` ✅
   - `sodium` ✅ (utilisé pour les magic link tokens)
4. Cliquez **Démarrer tout** (bouton vert) → Apache + MariaDB up.
5. Vérifiez : ouvrez http://localhost — vous devez voir la page Laragon.

Auto-virtual hosts : Laragon crée automatiquement un domaine `*.test` pour chaque dossier dans `C:\laragon\www`. **Mais on travaille dans `D:\Claude\ttm-app`**, donc on configurera ça manuellement (voir §3).

### Mobile (React Native / Expo)

Node 18+ requis. Vous avez **Node v24** déjà installé ✅.

Installez les outils Expo globalement :
```powershell
npm install -g eas-cli
```

Optionnel mais recommandé pour tester l'app :
- **Expo Go** sur votre téléphone (App Store / Play Store) — scanne le QR code du serveur dev.
- **Android Studio** + un émulateur Android (si vous voulez tester sans téléphone).
- **Xcode** (Mac uniquement) pour l'émulateur iOS.

### Symfony CLI (recommandé)

Le binaire `symfony` simplifie la dev (serveur HTTPS local, gestion .env, etc.) : https://symfony.com/download

Téléchargez le `.exe`, placez-le dans un dossier ajouté au `PATH` (par ex. `C:\Tools\`).

## 2. Configurer Laragon pour pointer vers `D:\Claude\ttm-app`

Par défaut, Laragon sert `C:\laragon\www\<projet>`. On veut servir `D:\Claude\ttm-app\backend\public\` à `http://ttm-app.test`.

### Méthode : virtual host manuel

1. Ouvrez `C:\laragon\etc\apache2\sites-enabled\` dans l'explorateur.
2. Créez un fichier `auto.ttm-app.test.conf` avec ce contenu :
   ```apache
   <VirtualHost *:80>
       DocumentRoot "D:/Claude/ttm-app/backend/public"
       ServerName ttm-app.test
       ServerAlias *.ttm-app.test
       <Directory "D:/Claude/ttm-app/backend/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
3. Menu Laragon → **Apache → Reload**.
4. Le menu Laragon devrait aussi détecter le nouveau host : *Menu → Hosts* (sinon, ajoutez `127.0.0.1 ttm-app.test` à `C:\Windows\System32\drivers\etc\hosts` à la main, en mode admin).

Test : http://ttm-app.test devrait charger (404 normal pour l'instant — pas encore de code).

## 3. Créer la base de données

Ouvrez **HeidiSQL** (livré avec Laragon — menu Laragon → Database → HeidiSQL) :

1. Connexion par défaut : user `root`, mot de passe vide.
2. Créez la base `ttm_app` (collation `utf8mb4_unicode_ci`).
3. Optionnel : créez aussi `ttm_app_test` pour les tests automatisés.

Ou en ligne de commande :
```powershell
& "C:\laragon\bin\mysql\mariadb-*\bin\mysql.exe" -u root -e "CREATE DATABASE ttm_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

## 4. Premier lancement du backend

```powershell
cd D:\Claude\ttm-app\backend
composer install
copy .env .env.local
# éditez .env.local : remplir DATABASE_URL avec le user/pass MariaDB local
php bin/console doctrine:migrations:migrate
php bin/console lexik:jwt:generate-keypair  # génère JWT keys (config/jwt/)
php bin/console app:fixtures:demo  # comptes de démo
```

## 5. Premier lancement du mobile

```powershell
cd D:\Claude\ttm-app\mobile
npm install
npx expo start
```

Modifier `mobile/src/config.ts` : pointer `API_BASE_URL` vers `http://ttm-app.test/api` (ou l'IP locale si vous testez depuis votre téléphone — `http://192.168.x.x/api`).

## 6. Comptes de démo (après `app:fixtures:demo`)

| Rôle | Email | Mot de passe |
|---|---|---|
| Admin | admin@ttm.test | demo |
| Entraîneur | coach@ttm.test | demo |
| Adhérent | licencie@ttm.test | demo |

Tous ont aussi le magic link disponible.

## 7. Dépannage rapide

**`composer install` échoue sur `ext-intl`** → activer l'extension dans Laragon (PHP → Extensions).

**`php bin/console` dit "command not found"** → ouvrir un terminal *via Laragon* (menu Terminal), il a le PATH PHP préconfiguré. Sinon, lancez Laragon une fois pour qu'il configure le PATH système, puis ouvrez un nouveau PowerShell.

**Erreur Doctrine "server_version not configurable"** → dans `.env.local`, vérifiez que `DATABASE_URL` se termine par `?serverVersion=mariadb-10.6.0&charset=utf8mb4`.

**Apache 404 sur `/api/...`** → mod_rewrite pas activé. Menu Laragon → Apache → mod_rewrite (cocher).

**Push notifications ne marchent pas en dev** → c'est attendu sur Expo Go ; il faut un EAS Build (dev client) pour tester les push réelles. Pour de l'itération rapide, mockez en console.
