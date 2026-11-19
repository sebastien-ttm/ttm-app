# Inscription payante aux événements (HelloAsso Checkout)

Un événement peut avoir des **tarifs**. L'adhérent choisit ses tarifs et leurs quantités dans l'appli, clique sur
« S'inscrire et payer » et arrive sur la page de paiement HelloAsso **avec ses informations déjà remplies** (prénom, nom,
e-mail, date de naissance). Au retour, le serveur **vérifie le paiement auprès de HelloAsso** : l'inscription passe à
« payée » et la présence est confirmée (si l'événement est soumis au vote).

## Mise en route

1. **HelloAsso** : l'association doit être **vérifiée** (sinon HelloAsso refuse d'encaisser : erreur 409).
2. **Client API** (Mon compte > Intégrations et API) : droits **AccessTransactions** (lecture, déjà utilisée pour la
   vérification des paiements) et **Checkout** (créer des paiements).
3. **`.env.local` sur le serveur** :
   ```
   HELLOASSO_CLIENT_ID=…
   HELLOASSO_CLIENT_SECRET=…
   HELLOASSO_ORGANIZATION_SLUG=mon-asso        # la partie « mon-asso » de helloasso.com/associations/mon-asso/…
   HELLOASSO_API_URL=https://api.helloasso.com # bac à sable : https://api.helloasso-sandbox.com
   ```
   Le bac à sable a ses propres identifiants et sa propre association de test : l'appli affiche alors « Mode test ».
4. **Notifications** (facultatives mais recommandées, elles confirment le paiement même si l'adhérent ferme la page) :
   dans HelloAsso, déclarer l'adresse `https://<domaine de l'appli>/api/public/helloasso/notifications` (types Order et
   Payment). Elles ne sont jamais crues : elles servent seulement à déclencher la vérification auprès de HelloAsso.
5. **Déploiement** : `composer dump-autoload -o`, migration `Version20261121010000`, vider le cache.

## Utilisation (back-office)

- **Calendrier > événement > Tarifs** : un tarif = libellé, prix (0 € possible = option gratuite), places (vide = illimité),
  précision, profils (vide = tous ; sinon seuls les adhérents ayant l'un de ces profils voient le tarif).
- **Communication > Inscriptions payantes** : liste en lecture seule des commandes (statut, total, numéros HelloAsso).

## Fonctionnement

- Une commande « en attente » **réserve ses places 45 minutes** (délai après lequel HelloAsso considère le paiement
  abandonné). Annulée ou expirée, elle libère les places. La réservation se fait sous verrou : deux adhérents ne prennent
  pas la dernière place en même temps.
- Un adhérent n'a qu'**un paiement en attente à la fois** par événement ; il peut ensuite inscrire d'autres personnes
  (invités) par de nouvelles commandes.
- Total nul (ex. goûter gratuit) : l'inscription est enregistrée sans passer par HelloAsso.
- Une inscription payée **ne s'annule pas** depuis l'appli (remboursement : étape suivante).
- Les adresses de retour contiennent `?order=…&payment=return|back|error` ; le paramètre n'est jamais cru, seul l'appel
  authentifié à l'API HelloAsso fait foi.

## À vérifier au premier essai (bac à sable)

Le code n'a pas pu être exécuté avant la mise en ligne. Les journaux (`var/log`, canal `app`) contiennent le corps des
notifications reçues (« HelloAsso : notification reçue ») et des refus de l'API (« HelloAsso : requête refusée »).
Points à confirmer : forme exacte de la réponse `GET …/checkout-intents/{id}` (clé `order` non vide si payé), préremplissage
du formulaire, réception des notifications.
