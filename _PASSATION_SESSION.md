# 🔁 Passation de session — InResto API

> But : donner à la prochaine session le **contexte**, un **récap** de l'état et
> les **points en attente**. Pas un journal. Le détail de chaque module vit dans
> le code et l'historique git ; les décisions structurantes dans les **mémoires**.

## Contexte projet

- API **Laravel 13 / PHP 8.4**, auth **Sanctum** (Bearer), permissions **spatie**
  (guard `api`), BD **PostgreSQL**. Users en **UUID** → FK users = `foreignUuid`.
- Domaine : restauration **multi-site** (stock, achats, budgets, recettes,
  inventaires, salle/vente).
- Conventions : controllers fins + **Services** (métier) + **FormRequests**
  (validation) + **API Resources** + **Policies** (`feature.action`) ; réponses
  via `App\Helpers\ApiResponse` `{status,message,data}` ; routes sous **`/api/`**
  (pas de `/v1/`) ; exceptions métier dédiées rendues en JSON par `bootstrap/app.php`.
- ⚠️ **Tests** : `php artisan config:clear` avant `php artisan test` (le cache
  force pgsql sinon). **Pint** : `vendor/bin/pint --dirty --format agent` avant de finaliser.
- Front séparé : **`../inresto-app`** (Angular 21, zoneless, signals). Build `npm run build`.

## État actuel

- **282 tests verts, Pint clean, build front vert.**
- Modules livrés : achats (fournisseurs, catalogue, commandes fournisseur,
  réceptions, budgets/dépenses), **stock** (StockService), recettes (sous-recettes,
  fiches techniques versionnées), inventaires, **multi-site** + périmètre user,
  **Module Salle complet** (produits/cartes, tables, sessions caisse, commandes
  client + KDS + déstockage, paiements/remises/annulations/clôture).
- **NOUVEAU — Paramètres d'établissement + Logo + PDF (back + front).**
  - Table `parametres_etablissement` (1 fiche/site, `site_id` unique) :
    `GET`/`PUT /sites/{site}/parametres` (upsert), logo
    `POST`/`DELETE /sites/{site}/parametres/logo` (disque `public`). Modèle,
    service, controller, FormRequests, Resource (`logo_url`), Policy
    (`parametre_etablissement.*`), factory.
  - **`PdfService` + 4 documents** (dompdf `barryvdh/laravel-dompdf`, stream
    inline jamais stocké, templates Blade CSS2.1 sous `resources/views/pdf/`,
    helper `App\Helpers\Devise`) :
    `GET /commandes-fournisseur/{id}/pdf`, `/commandes-client/{id}/pdf` (facture),
    `/commandes-client/{id}/recu` (ticket 80mm), `/fiches-techniques/{id}/pdf`.
    Contrôleur dédié `PdfController` (modules métier intacts, lecture seule).
  - Front (`../inresto-app`) entièrement câblé : `core/api/parametre-etablissement`
    + `core/api/pdf`, écran paramètres + upload logo sur **site-detail**, boutons
    PDF dans cuisine (bon de commande), vente (facture + reçu), liste fiches (🖨).
  - **Décisions actées** (critère revente : zéro donnée établissement en dur) :
    titre du doc client = **« FACTURE »** + mention non-fiscale en pied (FNE émise
    ailleurs) ; permissions PDF = **réutilisation des `*.view`** de chaque module ;
    site d'en-tête du bon de commande = `?site_id=` sinon site `PRINCIPAL` ; coût
    fiche technique = **meilleur prix catalogue** (miroir lecture seule de
    `FicheTechniqueService`, pas le PMP). Test anti-régression : grep « Innov »
    interdit dans `resources/views/pdf/**`. Seule modif métier autorisée :
    colonne `reference_facture_normalisee` sur `commandes_client`.

- **NOUVEAU — Correctifs logo + périmètre super-admin.**
  - **Logo établissement** : l'échec « Téléversement impossible » venait de PHP
    (`post_max_size`/`upload_max_filesize` = **2M**), pas de Laravel — coupure au
    *Request Startup* avant validation. Corrigé côté **php.ini Herd → 8M** (dev).
    ⚠️ **à répliquer en prod** (php.ini / pool FPM). La règle applicative
    `logo max:2048` redevient le vrai garde-fou (422 propre). Aucun code modifié.
  - **Super-admin = tous les sites (bypass, décision actée [[module-utilisateurs-roles]])** :
    `User::sitesPerimetre()` (tous les sites actifs si super-admin, sinon `user_site`
    actifs) branchée sur `GET /sites/perimetre` + payload login/`/auth/me`. Pas de
    rattachement `user_site` forcé. `created_by` inchangé (orthogonal au pivot).
  - **Non-bug clarifié** : l'en-tête « Site principal » du bon de commande PDF suit
    le site **sélectionné dans la topbar** (backend honore `?site_id=`), pas un
    rattachement figé — le super-admin a déjà les 3 sites (il a `site.view`).
  - Correctif test : `SeedDemoDataTest` seed désormais les rôles (`seedRbac()`)
    avant `inresto:demo` (contrat : `db:seed` avant la démo). **351 tests verts.**

## Git

- **Committé** : socle + stock + achats + recettes + inventaires + Module Salle
  Lots A/B + backend Sites (dernier : `41e2401`).
- **⚠️ NON committé** (à relire/committer) : Module Salle **Lot C-1** (commandes
  client, KDS, déstockage) et **C-2** (paiements, remises, annulations, clôture) ;
  session **fiche produit + carte par catégories** ; **correctifs UX/validation**
  (ordre carte/catégorie, « sans catégorie » à l'ajout, commande fournisseur
  restreinte au catalogue, suppression table bloquée si commandes) ; **session
  Paramètres établissement + Logo + 4 PDF** (back + front, ci-dessus) — dont
  l'ajout de dépendance `barryvdh/laravel-dompdf` dans `composer.json`. Front
  `../inresto-app` également non committé.

## Points en attente

1. **Endpoints lecture stock par site** (`stock_courant`/`pmp`) + **mouvements** —
   non exposés. → prévu pour le **prochain module : Pilotage**.
2. **Carte** : `CarteService` + index unique partiel « une seule carte active par
   PDV » (aujourd'hui app-level via `UpdateCarteRequest`). Pas urgent.
3. **⚙️ Opérationnel (avant usage réel des PDF/logo)** : lancer `php artisan
   migrate` (2 nouvelles migrations : `parametres_etablissement` +
   `reference_facture_normalisee`) puis `php artisan storage:link` (servir les
   logos uploadés). Tests OK sans ça (sqlite en mémoire migre tout seul).

> Réglés cette session : ~~PDF bon de commande (CDC 3.1.4)~~ = **fait** (dompdf
> retenu, 4 documents livrés back + front). Sessions précédentes : ~~fiche
> technique (champs proto)~~ = maquette ; ~~menus/formules~~ = flag `est_menu`
> documenté ; ~~suppression table porteuse de commandes~~ = **bloquée** (409).

## Mémoires liées (décisions structurantes)

`architecture-multi-site` · `pmp-decimal-precision` · `unites-de-mesure-etiquettes`
· `convention-seeders-vs-demo` · `carte-organisation-categories` ·
`commande-fournisseur-catalogue` · `front-app-inresto`.
