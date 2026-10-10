# Mailings groupés

Écran d'administration **Communication → Mailings** (réservé aux administrateurs) pour envoyer un e-mail à tous
les adhérents, ou à certains profils, sans se faire classer en spam ni dépasser les limites d'envoi.

## Utilisation

1. **Rédiger** : sujet, contenu (éditeur riche), profils visés (vide = tous les adhérents), comptes externes
   (parents non licenciés, amis du club) en option, adresse de réponse. `{{ prenom }}` et `{{ nom }}` sont remplacés
   pour chaque destinataire. L'en-tête du club et le lien de désinscription sont ajoutés automatiquement.
2. **Vérifier** : l'écran du mailing montre le nombre exact de destinataires (désinscrits, adresses invalides et doublons
   exclus), l'aperçu du mail et la liste des destinataires.
3. **M'envoyer un test** : arrive comme pour les adhérents, objet précédé de « [TEST] ».
4. **Lancer l'envoi** : la liste est figée, puis l'envoi part par lots espacés. L'écran se met à jour tout seul.
5. **Suivre** : envoyés / en attente / en échec / écartés, avec pause, reprise, annulation et relance des échecs.

## Comment l'envoi est protégé

| Risque | Parade |
| --- | --- |
| Être pris pour du spam | Envoi individuel (jamais en copie cachée), en-têtes `List-Unsubscribe` + `List-Unsubscribe-Post` (désinscription en un clic), lien de désinscription dans chaque mail, SPF/DKIM/DMARC alignés sur `triathlontoulousemetropole.com` |
| Débit trop fort | Lots de 25 mails, un lot par minute (400 adhérents ≈ 15 à 30 minutes) |
| Limite du fournisseur (Google Workspace : 2 000 mails/jour pour tout le compte) | Plafond de 1 200 mailings sur 24 h glissantes ; au-delà l'envoi attend et reprend tout seul |
| Panne SMTP ou quota dépassé | Après 3 échecs d'affilée, le mailing se met en **pause** (visible à l'écran) ; « Reprendre » après correction |
| Doublons | Un destinataire par adresse (parent et enfant qui partagent une adresse) ; relance impossible d'un mailing déjà lancé |
| Désinscrits | Écartés au lancement **et** revérifiés avant chaque envoi ; un désinscrit exclut toute son adresse |

Réglages dans `backend/config/services.yaml` : `app.mailing.batch_size`, `app.mailing.batch_delay_seconds`,
`app.mailing.daily_limit`.

## Désinscription

- Lien « Se désinscrire des mailings du club » dans chaque mail → page `/api/public/mailing/unsubscribe/{id}/{signature}`
  (signature HMAC de l'identifiant avec `APP_SECRET` : impossible à deviner). Un simple GET ne désinscrit jamais
  (les antivirus ouvrent les liens) ; il faut valider. La désinscription « en un clic » de Gmail/Yahoo envoie un POST à
  la même adresse. La page propose aussi de se **réinscrire**.
- Réglage « Mailings du club » dans le **profil de l'appli** (Notifications par e-mail), activé par défaut.
- Seuls les mailings sont concernés : les messages de compte (lien de connexion, messages reçus…) continuent.
- Stockage : `user.mailing_opt_out_at` (date de désinscription, `NULL` = reçoit les mailings).

## Exploitation

- L'envoi passe par la file **Messenger** : la tâche cron `messenger:consume async` (voir `DEPLOYMENT-O2SWITCH.md`) doit
  tourner, sinon un mailing lancé reste « en cours » sans rien envoyer. Bouton « Relancer le traitement » si l'envoi
  paraît bloqué.
- Après déploiement : `composer dump-autoload --no-dev -o` **avant** toute commande console (nouvelles classes), puis la
  migration `Version20261010210000` (tables `mailing`, `mailing_recipient`, colonne `user.mailing_opt_out_at`).
- Expéditeur : `MAILER_FROM` doit être une adresse de `triathlontoulousemetropole.com` (authentifiée). Test de la
  configuration : `php bin/console app:mailer:test adresse@exemple.fr --env=prod`, puis « Afficher l'original » dans Gmail
  (SPF, DKIM, DMARC = PASS).
- Les réponses des adhérents vont à l'adresse de réponse du mailing (l'expéditeur est `noreply`).
