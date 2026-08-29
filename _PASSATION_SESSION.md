# 🔁 Passation de session — InResto API

> **But** : permettre à une **nouvelle session Claude Code** de démarrer avec une
> bonne base de contexte — le **contexte projet**, l'**état global** et les
> **points d'attention**.
> **Ce n'est pas un journal** : l'historique des modifications vit dans git, le
> détail de chaque module dans le code, les règles métier dans
> **`NOTES-DE-GESTION.md`**, et les décisions structurantes dans les **mémoires**.
> On met ce fichier à *jour* (on remplace l'état), on ne l'*empile* pas.

## Contexte projet

- API **Laravel 13 / PHP 8.4**, auth **Sanctum** (Bearer), permissions **spatie**
  (guard `api`), BD **PostgreSQL**. Users en **UUID** → FK users = `foreignUuid`.
- Domaine : restauration **multi-site** (stock, achats, budgets, recettes,
  inventaires, salle/vente, caisse, reporting).
- Conventions : controllers fins + **Services** (métier) + **FormRequests**
  (validation) + **API Resources** + **Policies** (`feature.action`) ; réponses
  via `App\Helpers\ApiResponse` `{status,message,data}` ; routes sous **`/api/`**
  (pas de `/v1/`) ; exceptions métier dédiées rendues en JSON par `bootstrap/app.php`.
- **Enums fermés plutôt que texte libre** pour toute nature métier
  (`TypeImpression`, `DestinationVersement`, `StatutTable`, `SortAnnulation`) :
  une saisie ouverte donne « coffre », « Coffre » et « au coffre » au bout d'un
  mois, et plus rien d'interrogeable.
- ⚠️ **Tests** : `php artisan config:clear` avant `php artisan test` si un cache de
  config traîne (il force pgsql sinon ; la suite tourne en SQLite `:memory:`).
  La `memory_limit` est fixée à 512M dans `phpunit.xml`. **Pint** :
  `vendor/bin/pint --dirty --format agent` avant de finaliser.
- Front séparé : **`../inresto-app`** (Angular 21, zoneless, signals). Build
  `npx ng build`, tests `npx ng test --watch=false` (Vitest + jsdom, **pas** Jasmine).

## État global

- **673 tests API verts**, **215 tests front verts**, builds propres, Pint et
  Prettier clean.
- **Travail en cours sur la branche `feat/pass-cuisine`**, dans les **deux**
  dépôts, **ni fusionnée ni poussée** :
  - Distants : `git@github.com:BKODEV/inresto-api.git` · `…/inresto-app.git`.
- Modules livrés (back testé + front câblé) :
  - **Socle & multi-site** : sites, points de vente, caisses, périmètre user par
    site — **appliqué par le serveur** (voir Décisions structurantes).
  - **Achats** : fournisseurs, catalogue, commandes fournisseur, réceptions,
    budgets & dépenses.
  - **Stock** : `StockService` (PMP), inventaires, lecture par site et journal
    des mouvements.
  - **Recettes** : sous-recettes, fiches techniques versionnées, **méthodologie**
    (étapes ordonnées, matériel, points critiques) versionnée avec la fiche et
    imprimée sur le PDF.
  - **Salle / vente** : produits & cartes, tables (archivage réversible, création
    en lot), commandes client **servies par tours**, KDS adossé aux **lignes**,
    déstockage, remises / annulations.
  - **Poste de caisse** (`caisse.layout` côté front) : plan de salle, file
    d'encaissement, sessions, ventes du service, pourboires.
  - **Fermeture de caisse** : rapprochement **contenant par contenant**,
    tolérance d'écart par site, sens d'écart qualifié, **versements**,
    **écart de transition** entre deux sessions, **fermeture forcée** sous
    permission, rapport Z 80mm.
  - **Pourboires** : colonne dédiée sur le paiement, soldes par serveur,
    reversement par versement `personnel`, écran « Mes pourboires » pour le serveur.
  - **Journal d'activité** : frise chronologique **en lecture seule**, agrège les
    **six** sources des autres modules (annulations, remises, paiements,
    encaissements, versements, impressions) sans jamais écrire. Filtrable par
    type, dates, site et **point de vente**.
  - **Relecture remises / annulations** : `GET /api/remises` et
    `GET /api/annulations`, paginées et filtrées, sous `remise.view` et
    `annulation.view`.
  - **Postes de production cuisine** : `postes_cuisine` + catégories associées,
    filtre `poste_cuisine_id` sur le KDS, écran Pass.
  - **Services de cuisine** : `sessions_cuisine` par point de vente, brigade en
    pivot, rattachement des lignes à l'envoi, rapport de production. Onglet
    « Cuisine » du poste de caisse.
  - **Menu public par QR** : jeton **par table**, seul périmètre non authentifié.
  - **PDF** : bon de commande, fiche technique, facture A4, **addition et reçu**
    (un gabarit 80mm à deux états), rapport de fermeture, planches QR.
  - **Utilisateurs & Rôles** : RBAC admin complet, commande `inresto:install`.
  - **Reporting** (lecture seule) : ventes, food cost, stock, budget, dashboard.
- **Données de démo** : `inresto:demo` regénère un jeu complet ;
  `inresto:demo-carte` est **additive et idempotente**.

## Décisions structurantes

Le **pourquoi** de chaque règle métier est dans **`NOTES-DE-GESTION.md`**, les
décisions transverses dans les **mémoires**. À retenir en tête :

- **Le pivot `user_site` est une frontière appliquée par le serveur**, pas un
  confort d'affichage : `App\Support\SitePerimetre` borne les lectures,
  `App\Rules\SiteDansPerimetre` les écritures. **Deux notions de « global »** :
  en opérationnel, seul le super-admin dépasse son périmètre ; `reporting.global`
  n'élargit que la lecture analytique → [[perimetre-site-applique]].
- **Au moins un site est obligatoire à la création d'un compte**, sans quoi
  l'application du périmètre produirait des comptes en 403 permanent.
- **Super-admin = périmètre « tous les sites »** (bypass, pas de rattachement
  `user_site`) → [[module-utilisateurs-roles]].
- **Un mode de paiement est un contenant** (`compte_en_caisse`) : le théorique de
  caisse ne compte que ce qui finit **dans le tiroir**, et chaque contenant se
  rapproche séparément → [[modele-encaissement-comptoir]].
- **On ne souffle jamais le montant attendu** avant que le caissier ait compté —
  ni le théorique, ni le fonds à reprendre. Un montant affiché se recopie, et la
  comparaison ne vaut alors plus rien. Vrai à la fermeture, contenant par
  contenant, et à l'ouverture.
- **Les valeurs d'audit sont figées, jamais recalculées** : `montant_theorique`,
  `montant_reel`, `ecart`, `ecart_ouverture`, le théorique par mode, la fiche
  technique de la ligne vendue, le `table_numero` du document imprimé.
- **Le CA est structurellement immunisé contre les pourboires** : tout le
  reporting lit `Σ quantité × prix_unitaire` sur les lignes, jamais les
  paiements. Seule la vue trésorerie lit les paiements, et somme `montant` seul.
- **Rien ne bloque un service** : une vente n'est jamais refusée pour stock
  insuffisant, une ouverture de caisse jamais refusée pour écart de transition.
  Le système **signale**, il n'immobilise pas. Les seuls vrais blocages sont
  l'impayé à la fermeture et le contenant non compté (ce dernier contournable
  sous permission).
- **PDF orientés revente** : zéro donnée d'établissement en dur (test
  anti-régression), en-tête pris sur le site d'impression.
- **Une entrée de menu porte la même permission que l'API derrière elle**, et
  la racine / la sortie de connexion / la route inconnue résolvent la **première
  entrée ouverte** à l'utilisateur — jamais `/dashboard` en dur, qui exige
  `reporting.dashboard`.
- **Front : la portée de site est un tri-état** — « à choisir », un site, ou la
  vue consolidée. Les écrans opérationnels refusent le mode consolidé
  → [[perimetre-site-applique]].

## Points d'attention

### Paramétrage à faire dans l'application

1. **Modes de paiement** : la fermeture exige désormais qu'un compte soit saisi
   pour **chaque mode actif**. Il faut donc désactiver « Virements » et
   « Chèques » (non acceptés dans ce restaurant), et créer « Carte bancaire »
   **décoché « en tiroir »** quand le TPE arrivera.
2. **`pourboire.suivre_les_siens`** n'est attribuée à **aucun rôle** par choix :
   l'écran « Mes pourboires » existe mais reste invisible tant que la direction
   ne l'accorde pas. `session_caisse.forcer_fermeture` est allée au **gérant**,
   pas au caissier.
3. **Nouvelles permissions** à semer : `session_cuisine.view|ouvrir|fermer`
   (chef-cuisine les trois, gérant la lecture seule). Elles sont dans
   `PermissionCatalog` et `RoleSeeder` — donc couvertes par le point suivant.
4. Après un déploiement, enchaîner **les deux** seeders :
   `db:seed --class=PermissionSeeder` (enregistre les permissions) puis
   `db:seed --class=RoleSeeder` (les attribue aux rôles système).
   ⚠️ **`RoleSeeder` fait un `syncPermissions`** : il **REMPLACE** le jeu de
   permissions de chaque rôle système par celui du code. Toute permission
   accordée à la main depuis l'écran des rôles — `pourboire.suivre_les_siens` au
   premier chef — est **silencieusement révoquée** au prochain passage. Si la
   direction décide durablement d'ouvrir un accès, il faut l'inscrire dans
   `RoleSeeder`, pas seulement dans l'interface.

### Prérequis déploiement

5. `php artisan migrate` puis `php artisan storage:link` (logos **et** photos).
6. **php.ini prod** : `post_max_size` = `upload_max_filesize` = **8M** (sinon
   upload logo coupé au *Request Startup*, avant Laravel).
7. **`FRONTEND_URL` doit pointer sur le front**, jamais sur l'API. Elle sert aux
   liens de réinitialisation de mot de passe **et** aux QR de table
   (`PUBLIC_MENU_BASE_URL` → `FRONTEND_URL` → `APP_URL`). Absente, la chaîne de
   repli tombait sur `APP_URL` et les QR menaient à un 404 sur l'API — c'était le
   cas en dev jusqu'à aujourd'hui. Elle figure maintenant dans `.env.example`.
   **Les QR déjà imprimés portent l'ancienne URL et sont à réimprimer.**
8. **SMTP** configuré (reset mots de passe).
9. Avant d'appliquer le périmètre en prod, vérifier qu'aucun compte n'est
   orphelin : `User::doesntHave('sites')->count()` doit valoir 0.

### Pièges techniques rencontrés (à ne pas redécouvrir)

10. **SQLite reconstruit la table dès qu'on ajoute une clé étrangère**, et la
   reconstruction **perd silencieusement les index partiels et les contraintes
   CHECK**. Deux fois le cas cette session (index « une seule session ouverte »,
   contrainte `destination` des versements). Toute migration qui ajoute une FK
   doit **reposer explicitement** ce qu'elle risque d'emporter. Corollaire : les
   tests peuvent être verts pendant que PostgreSQL rejette — **vérifier sur la
   vraie base**.
11. **`enum()->change()` produit du SQL invalide sur PostgreSQL.** Utiliser une
    branche pgsql explicite (`ALTER COLUMN … TYPE` + `ADD CONSTRAINT`), ou
    convertir la colonne en `string` et laisser l'enum PHP faire la loi.
12. **Toute migration se valide par `migrate:rollback` puis re-migrate sur
    PostgreSQL**, pas seulement à l'aller.
13. **Budget de style Angular : 8 kB par composant.** Dépassé trois fois cette
    session ; la parade est d'extraire les blocs partagés dans un `.scss`
    voisin (`reglement.scss`, `caisse.page.scss`).
14. **Convention de test API** : chaque fichier définit son propre helper
    `actingAs…`. Réutiliser un nom déjà pris provoque une **erreur fatale PHP**
    (redéclaration) — la suite sort en code 255 **sans aucune sortie**, alors que
    chaque fichier passe isolément.
15. `caisse.layout.spec.ts` laisse passer **2 « unhandled rejections »** du
    routeur Angular (`NG04002` sur `vente/caisse/ventes` et `…/pourboires`) : le
    test ne déclare pas ces routes. La suite passe, mais Vitest prévient à juste
    titre que ce genre de bruit peut masquer un faux positif — à nettoyer.
16. **Un écran peut être entièrement codé et rester inatteignable.** Le tiroir de
    versement, `openVerser()` et ses tests unitaires existaient depuis des
    semaines : aucun élément du gabarit ne l'appelait. Un test de *classe* ne
    voit pas ce trou — il faut un test de **rendu** (`TestBed.createComponent`).
    Même famille : `choisirMode()` à l'encaissement, documenté « des boutons et
    non une liste » pendant que le gabarit gardait le `<select>`.

### Dettes connues

17. **Le site `PRINCIPAL` n'a pas forcément de fiche d'établissement** : tout PDF
    passant par le repli sortira sans en-tête.
18. **Périmètre non appliqué sur `points_de_vente` et `parametre_etablissement`**,
    qui portent pourtant un `site_id`. Écrans réservés à des rôles
    administratifs, donc enjeu moindre — mais ils restent hors de la frontière.
19. **`tests/Feature/CommandeClientTest.php` « KDS : restreint la file au poste »
    est INSTABLE** (≈1 échec sur 3 exécutions de la suite complète) : les deux
    lignes sont créées avec `envoyee_le = now()` et le KDS groupe les tours par
    horodatage ISO — à cheval sur une seconde, elles forment deux tours au lieu
    d'un. Une seconde figée le règle.
20. **Carte** : index unique partiel « une seule carte active par PDV » resté
    app-level (`UpdateCarteRequest`). Pas urgent.

## Mémoires liées (décisions structurantes)

`architecture-multi-site` · `perimetre-site-applique` · `module-utilisateurs-roles` ·
`module-reporting` · `module-menu-public-qr` · `modele-encaissement-comptoir` ·
`caisse-addition-vs-recu` · `pmp-decimal-precision` · `unites-de-mesure-etiquettes` ·
`convention-seeders-vs-demo` · `carte-organisation-categories` ·
`commande-fournisseur-catalogue` · `front-app-inresto`.
