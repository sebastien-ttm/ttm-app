# Emprunt du minibus

Gestion de l'emprunt du minibus du club, réservée pour l'instant aux **membres du comité directeur** (CoDir). Elle remplace
le formulaire Google de la fiche « Bienvenue dans le minibus TTM » : la demande, la charte et les états des lieux
(départ / retour) passent tous par l'application.

## Parcours

1. **Demande** — un membre du CoDir ouvre *Staff › Comité directeur › Emprunt minibus*, consulte le calendrier et touche
   « Demander le minibus » : premier jour, dernier jour (facultatif), **raison**, remorque. Le minibus est « libre » tant
   qu'aucune demande *validée* ne couvre les jours demandés (les demandes en attente peuvent se chevaucher : le
   gestionnaire arbitre).
2. **Validation** — le **gestionnaire du minibus** voit les demandes en tête de l'onglet (pastille rouge sur le sous-onglet) et
   dans l'administration (*Comité directeur › Emprunt du minibus*). Il valide ou refuse, avec un message facultatif à
   l'emprunteur. Valider une demande qui chevauche une demande déjà validée est refusé par le serveur.
3. **Charte** — dès que la demande est validée, la charte d'utilisation est présentée à l'emprunteur, qui doit cocher
   « J'ai lu… » et l'accepter. La date d'acceptation et la **version** de la charte (empreinte du texte) sont conservées.
4. **État des lieux au départ** — à partir de la veille de l'emprunt : photos (appareil photo ou galerie, 12 maximum),
   kilométrage (facultatif), comment cela s'est passé, problème éventuel (avec description obligatoire).
5. **État des lieux au retour** — mêmes champs, plus « plein refait » et « minibus nettoyé et vidé ».

Tant qu'aucun état des lieux n'est enregistré, l'emprunteur ou le gestionnaire peut **annuler** la demande. Un état des lieux
peut être complété ensuite (les nouvelles photos s'ajoutent ; une photo se supprime tant qu'il en reste au moins une).

## Rôles

| Rôle | Droit |
|---|---|
| Membre du CoDir (`boardRole` renseigné) | voir le calendrier, demander, suivre ses emprunts, consulter les états des lieux |
| **Gestionnaire du minibus** | tout ce qui précède + valider / refuser, annuler, supprimer des photos, accès aux pages d'administration |

Le gestionnaire est une **case complémentaire** du profil CoDir : *Administration › Utilisateurs › Gestionnaire du minibus*
(le rôle CoDir doit être renseigné : sans lui la case est ignorée, et retirer le rôle CoDir la décoche). Plusieurs
gestionnaires sont possibles.
Un administrateur (`ROLE_ADMIN`) a les mêmes droits que le gestionnaire.

## Charte modifiable

*Administration › Comité directeur › Charte du minibus* : titre + texte (éditeur riche). Un texte par défaut, tiré de la
fiche papier, est inséré par la migration. À chaque modification, la version change ; les emprunteurs voient toujours la
version en vigueur au moment où ils la lisent.

## Technique

- **Entités** : `MinibusBooking`, `MinibusCheck` (un par type et par emprunt), `MinibusCheckPhoto`, `MinibusSettings`
  (singleton : la charte). Champ `User::minibusManager`.
- **Droits** : `MinibusVoter` — `MINIBUS` (membre du CoDir) et `MINIBUS_MANAGE` (gestionnaire ou administrateur).
- **API** (`/api/minibus`, JWT) : `GET /bookings`, `GET /bookings/{id}`, `POST /bookings`, `POST /bookings/{id}/decision`,
  `/cancel`, `/charter`, `/checks/{departure|return}` (multipart `photos[]`), `GET|DELETE /photos/{id}`.
- **Photos** : stockées **hors du dossier public** (`var/uploads/minibus/{emprunt}`), redimensionnées (1600 px, JPEG),
  servies uniquement par un point d'accès authentifié (jeton `?bearer=` pour les balises image du client).
- **Administration** : `/admin/minibus` (demandes, décision, détail des états des lieux avec photos, annulation,
  suppression pour un administrateur).
- **Application** : `CodirTab` (sous-onglets *Réunions* | *Emprunt minibus*), `MinibusTab` (calendrier + listes),
  `app/minibus/new.tsx` (demande), `app/minibus/[id].tsx` (détail : décision, charte, états des lieux).
- **Notifications** : aucune pour l'instant (pastilles dans l'application seulement). Un e-mail / une notification
  au gestionnaire à chaque demande, puis à l'emprunteur à chaque décision, serait un ajout simple.

## Déploiement

1. `composer dump-autoload -o` (nouvelles classes).
2. Exécuter la migration `Version20261123010000` **avant** de déployer le code (colonne `user.minibus_manager`, quatre
   tables, charte par défaut).
3. `php bin/console cache:clear`.
4. Dans l'administration, cocher « Gestionnaire du minibus » sur le profil du ou des gestionnaires, puis relire la charte.

Le dossier `var/uploads/minibus` est créé à la première photo.
