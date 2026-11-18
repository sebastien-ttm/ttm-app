# Invitations Google Agenda (réunions du CoDir)

Quand un administrateur **programme une réunion du comité directeur** (backend → Réunions du CODIR), l'application crée
un événement **Google Agenda** et envoie l'invitation. Google envoie lui-même les invitations (elles ne comptent pas
dans le plafond de 2 000 mails/jour de l'envoi SMTP).

## Qui est invité

**Pour le moment : une seule adresse générique**, `codir@triathlontoulousemetropole.com` (réglage
`GOOGLE_CALENDAR_GENERIC_ATTENDEE`). Il faut que cette adresse existe dans le Workspace (idéalement un **groupe Google**
dont les membres sont les membres du CoDir et les entraîneurs invités : chacun reçoit alors l'invitation).

**Plus tard : chacun individuellement.** Vider `GOOGLE_CALENDAR_GENERIC_ATTENDEE` (`GOOGLE_CALENDAR_GENERIC_ATTENDEE=`
dans `.env.local`) : l'événement invite alors chaque **membre du CoDir** actif et chaque **entraîneur** actif (invités
aux réunions, comme pour la notification push), à son **e-mail du club** ou, à défaut, à son **e-mail personnel**.

- L'**e-mail du club** se saisit dans la fiche utilisateur (backend → Utilisateurs → « E-mail du club », par exemple
  `prenom.nom@triathlontoulousemetropole.com`). Il est distinct de l'e-mail personnel (celui de FFTRi) et n'est jamais
  modifié par l'import du CSV FFTRi. Il peut être renseigné dès maintenant : il ne sert qu'au passage aux invitations
  individuelles.
- Les adresses en double (parent et enfant sur une même boîte) ne reçoivent qu'une invitation.

L'événement s'appelle « TTM · CODIR du 24/11/2026 », dure 2 h (réglage `app.board.meeting_duration_minutes` dans
`backend/config/services.yaml`), reprend le lieu et les informations de la réunion et renvoie vers l'ordre du jour de
l'appli. Pas d'invitation pour une réunion déjà passée.

Sans configuration, rien n'est envoyé et le backend l'indique (message orange à la programmation d'une réunion).

## Mise en place (une fois)

L'application agit au nom d'un utilisateur du Google Workspace du club (l'**organisateur** des réunions, par exemple
la secrétaire) grâce à un **compte de service** avec *délégation à l'échelle du domaine* — c'est la seule façon de
pouvoir inviter des participants.

1. **Console Google Cloud** (<https://console.cloud.google.com>), avec un compte du Workspace :
   - créer un projet (ex. « TTM app ») et y activer l'API **Google Calendar API** ;
   - *IAM et administration → Comptes de service* : créer un compte de service (ex. `ttm-calendar`), puis *Clés →
     Ajouter une clé → JSON* : un fichier `.json` est téléchargé ;
   - noter l'**ID client** (numérique, « ID unique ») du compte de service.
2. **Console d'administration Google Workspace** (<https://admin.google.com>) → *Sécurité → Contrôle des accès et des
   données → Commandes d'API → Délégation à l'échelle du domaine → Ajouter* :
   - **ID client** : celui du compte de service ;
   - **Portée OAuth** : `https://www.googleapis.com/auth/calendar.events`.
3. **Serveur** : déposer le fichier JSON **hors de `public/`**, par exemple
   `backend/var/secrets/google-calendar.json` (droits 600). Ne jamais le commiter ni l'envoyer par e-mail.
4. Dans `backend/.env.local` :

   ```
   GOOGLE_CALENDAR_CREDENTIALS=var/secrets/google-calendar.json
   GOOGLE_CALENDAR_IMPERSONATE=secretaire@triathlontoulousemetropole.com
   GOOGLE_CALENDAR_ID=primary
   GOOGLE_CALENDAR_GENERIC_ATTENDEE=codir@triathlontoulousemetropole.com
   ```

   - `GOOGLE_CALENDAR_IMPERSONATE` : l'utilisateur du Workspace qui sera l'organisateur (son agenda reçoit les événements) ;
   - `GOOGLE_CALENDAR_ID` : `primary` = l'agenda de cet utilisateur ; ou l'identifiant d'un agenda partagé sur lequel
     il a le droit « Apporter des modifications aux événements ».
   - Si le serveur utilise `.env.local.php` : relancer `composer dump-env prod`.
5. Le **worker Messenger** (cron) doit tourner : l'envoi est asynchrone, comme les autres e-mails.

## Utilisation

- **À la programmation** d'une réunion à venir : l'événement est créé et les invitations partent d'office.
- **Réunion programmée avant la mise en service**, ou invitation à renvoyer : backend → Réunions du CODIR → *En séance*
  → bouton **Inviter via Google Agenda** (administrateurs). Si un événement existe déjà, le bouton devient **Renvoyer
  l'invitation** : l'ancien événement est supprimé (annulation envoyée aux invités) et un nouveau est créé.
- Une mention « 📅 Invitation Google Agenda envoyée » s'affiche sur la réunion, et la colonne « Invitation Google
  Agenda » de la liste des réunions la reflète.

## Limites

- L'événement n'est **pas synchronisé** ensuite : modifier la date ou le lieu d'une réunion dans le backend ne met pas à
  jour Google Agenda (utiliser « Renvoyer l'invitation », ou modifier l'événement dans Google Agenda).
- Les réponses des invités (oui / non / peut-être) se consultent dans Google Agenda, pas dans l'appli.

## Dépannage

| Symptôme | Piste |
| --- | --- |
| « Google Agenda n'est pas configuré » | Variables absentes, fichier JSON introuvable (chemin relatif à `backend/`) ou illisible. |
| Rien n'arrive, pas d'erreur visible | Le worker Messenger ne tourne pas. `php bin/console messenger:consume async -vv --time-limit=60` pour tester. |
| `php bin/console messenger:failed:show` liste un message | Lire l'erreur : `unauthorized_client` = délégation à l'échelle du domaine absente ou portée / ID client incorrects ; `403` = l'utilisateur à usurper n'a pas le droit d'écrire sur l'agenda ciblé ; `404` = `GOOGLE_CALENDAR_ID` inconnu. |
| Personne ne reçoit l'invitation (adresse générique) | Vérifier que `codir@…` existe dans le Workspace, et si c'est un groupe Google, qu'il contient bien les membres et qu'il accepte les e-mails externes / les invitations d'agenda. |
| Un invité ne reçoit rien (mode individuel) | Vérifier son « E-mail du club » (ou personnel) dans sa fiche utilisateur, et que son compte est actif. |
