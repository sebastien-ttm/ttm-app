# Déploiement — O2Switch mutualisé

> Sera détaillé en fin de projet. Squelette d'étapes ci-dessous pour anticiper les contraintes.

## Pré-requis côté O2Switch

- Offre **Cpanel Unique** (~7€/mois).
- Activer **PHP 8.3** dans cPanel → Sélecteur PHP → choisir 8.3, activer extensions : `intl`, `mbstring`, `openssl`, `zip`, `gd`, `fileinfo`, `sodium`, `pdo_mysql`.
- Créer une base **MariaDB** + user dédié (cPanel → Bases de données MySQL).
- Activer **SSH** (gratuit chez O2Switch sur demande / clé).

## Stratégie de déploiement

### Option A : Git pull via SSH (recommandée)
1. SSH dans le compte O2Switch.
2. `git clone` du repo dans `~/ttm-app/`.
3. Symlink : `~/public_html` → `~/ttm-app/backend/public`.
4. `composer install --no-dev --optimize-autoloader`.
5. Migration : `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`.
6. Vidage cache : `php bin/console cache:clear --env=prod`.

### Option B : déploiement par FTP
- Build local : `composer install --no-dev`, `php bin/console cache:warmup --env=prod`, supprimer `var/cache/dev/`, `var/log/*`, `vendor/bin/.phpunit/`.
- Upload `backend/` dans `~/ttm-app/`.
- Pointer `~/public_html` vers `~/ttm-app/backend/public` (cPanel → Domaines → Sous-domaines, ou symlink SSH).
- Migrations : via *cPanel → Tâches Cron* ou SSH ponctuel.

## Configuration PHP (`.user.ini` dans `public/`)

```ini
upload_max_filesize = 16M
post_max_size = 20M
memory_limit = 256M
max_execution_time = 60
opcache.memory_consumption = 128
opcache.preload = /home/USER/ttm-app/backend/config/preload.php
```

## Variables d'environnement (`.env.local`)

```
APP_ENV=prod
APP_SECRET=<32 octets hex>
APP_DEBUG=0
DATABASE_URL="mysql://USER:PASSWORD@localhost:3306/USER_ttm?serverVersion=mariadb-10.6.0&charset=utf8mb4"
MAILER_DNS=smtp://USER%40DOMAINE.TLD:PASSWORD@DOMAINE.TLD:465?encryption=ssl
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=<phrase>
EXPO_ACCESS_TOKEN=<token expo, optionnel>
TRUSTED_HOSTS='^(www\.)?ttm-toulouse\.fr$'
TRUSTED_PROXIES=127.0.0.1
```

## Tâches Cron (cPanel → Tâches Cron)

```
# Consommer la file Messenger pour push notifications (toutes les minutes)
* * * * * cd /home/USER/ttm-app/backend && php bin/console messenger:consume async --time-limit=55 --memory-limit=128M --quiet

# Nettoyage hebdo des magic link tokens expirés
0 3 * * 0 cd /home/USER/ttm-app/backend && php bin/console app:tokens:cleanup
```

## Mobile — distribution

- `eas build --platform all --profile production`
- Soumission App Store / Play Store via `eas submit`.
- Configuration EAS dans `mobile/eas.json`.
- L'API doit être accessible en HTTPS (certificat Let's Encrypt fourni par O2Switch).

## Checklist de mise en prod

- [ ] HTTPS forcé via `.htaccess`.
- [ ] `APP_ENV=prod` et `APP_DEBUG=0`.
- [ ] Cron Messenger actif.
- [ ] Premier import CSV fait (compte admin défini avant).
- [ ] Test parcours adhérent : magic link → consulter article → réagir → commenter.
- [ ] Test parcours coach : poster un PDF → push reçue côté téléphone.
- [ ] Sauvegardes automatiques activées (cPanel → Sauvegardes).
- [ ] DNS production pointe vers O2Switch (A record).
