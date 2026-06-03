# 🔁 Passation de session — InResto API (TEMPORAIRE, à supprimer)

> Contexte pour reprendre le développement à la prochaine session Claude Code :
> ce qui a été ajouté et où on s'est arrêté.
>
> État git : branche **`main`**. Les Lots 2 & 3 décrits ci-dessous sont
> **committés** (`fc4ea52` « Module cusine & stock ok », par-dessus `5b3e8f0`
> stock multi-site + commande fournisseur). Le dernier commit ajoute le
> correctif morph map, l'enrichissement de `inresto:demo` et les tests associés.
>
> **⚠️ NON committé** : exposition + CRUD des **sites** (backend, §4), le câblage
> du **front Angular** (§5), le **Module Salle — Lot A : Produits & Carte-Produit**
> (§6) ET le **Lot B : Tables & Sessions de caisse** (§7). À relire et committer.
>
> **État : 198 tests passent (488 assertions), Pint clean.**

---

## 1. Contexte projet

- API Laravel 13 / PHP 8.4, auth **Sanctum** (tokens Bearer), permissions **spatie/laravel-permission** (guard `api`).
- BD : **PostgreSQL**. Users en **UUID** → toute FK vers `users` = `foreignUuid` (les autres FK = `foreignId`).
- Domaine : gestion de restauration multi-site (stock, achats, budgets, recettes, inventaires).
- Conventions : controllers fins sous `App\Http\Controllers\Api\` + **Services** pour le métier, **FormRequests** pour la validation, **API Resources** pour les réponses, **Policies** (permission `feature.action`), réponses via `App\Helpers\ApiResponse`. Routes sous **`/api/`** (PAS de `/api/v1/`).
- Exceptions métier dédiées par module, rendues en JSON par le handler global (`bootstrap/app.php`) via `ApiResponse::custom($status, 'error', $message)`.
- ⚠️ **Tests** : la config en cache force `pgsql`. Toujours lancer `php artisan config:clear` avant `php artisan test` (sinon les tests tapent sur pgsql au lieu du sqlite `:memory:` de `phpunit.xml`).
- ⚠️ **Pint** : `vendor/bin/pint --dirty --format agent` avant de finaliser.

---

## 2. Fonctionnalités

### 🟦 Lot 1 (committé `81085e0`) — CRUD de base + socle stock
CRUD : `fournisseurs`, `matieres-premieres`, `catalogue-fournisseur` (+ `sync`),
`budgets`, `points-de-vente`, paramètres (`caisses`, `cartes`,
`categories-produit`, `categories-matiere-premiere`, `modes-de-paiement`,
`unites-de-mesure`), auth.

### 🟩 Module STOCK — StockService (committé)
- `app/Enums/MouvementType.php`, `app/Exceptions/StockException.php`, `app/Models/MouvementStock.php`.
- `app/Services/StockService.php` — **SEUL** point qui écrit `mouvements_stock` / `stock_courant` / `pmp`. Opère sur le couple **(matière, site)** : `entree()`, `sortie()`, `correctionInventaire()`. Transaction + `lockForUpdate`, `firstOrCreate` de la ligne `stocks` (partagé par les 3 opérations), recalcul PMP.

### 🟩 Multi-site + périmètre utilisateur (committé)
Règle d'or : **DÉFINITIONS PARTAGÉES, OPÉRATIONS RATTACHÉES AU SITE.**
- Partagé (aucun `site_id`) : `matieres_premieres`, `fournisseurs`, `catalogue_fournisseur`, `unites_de_mesure`, catégories, **`sous_recettes`**, **`produits`**, **`fiches_techniques`**.
- Par site : `stocks`, `mouvements_stock`, `budgets`, `inventaires`.
- `user_site` (pivot, `est_principal`). `User::sites()` / `Site::users()`.

### 🟩 Module COMMANDE FOURNISSEUR (committé) — CRUD + machine à états
- `StatutCommandeFournisseur` (`brouillon → envoyee → partiellement_recue → cloturee` ; `* sauf cloturee → annulee`).
- `CommandeFournisseurService` (numéro `CF-AAAAMM-####`, recalcul `montant_previsionnel`, transitions). `aDesReceptions()` **désormais câblé** (voir Réception).
- Modifiable uniquement en `brouillon`. Ne touche jamais au stock.

---

### 🟧 Lot 2 — Étape 3 : RÉCEPTIONS (committé)
- Migration d'altération : `receptions.site_id` (FK sites). `lignes_reception` déjà posée.
- `app/Services/ReceptionService.php` : `confirmer(Reception, array $lignes, string $userId)`. **SEUL point qui déclenche une entrée de stock.** Transactionnel (tout ou rien) :
  - conversion unité d'achat → stockage : `qte_stockage = quantite_recue × catalogue.qte_par_unite_achat`, `prix_stockage = prix_unitaire_reel ÷ qte_par_unite_achat` ;
  - `StockService::entree(...)` sur le **site de la réception**, référence polymorphe `lignes_reception` ;
  - **une `Depense` `achat_fournisseur` par ligne** sur le budget du site/mois (absent → exception, rollback) ;
  - `montant_reel = Σ quantite_recue × prix_unitaire_reel` ;
  - transition commande : tout reçu (tolérance **1 %**) → `marquerCloturee()`, sinon `marquerPartiellementRecue()` (re-transition seulement depuis `envoyee`).
- `ReceptionException` (statut commande invalide 409, facteur conversion manquant 422, budget absent 422, déjà confirmée 409).
- Modèles `Reception` / `LigneReception`, controller (store crée l'entête, `confirmer` traite), Resources, FormRequests, `ReceptionPolicy` + permission `reception.*`. **Réception en 2 temps** (POST crée, POST `/confirmer` exécute).
- `CommandeFournisseur::receptions()` + `aDesReceptions()` câblé (bloque l'annulation d'une commande réceptionnée).
- Tests : `tests/Feature/ReceptionTest.php`.

### 🟧 Lot 2 — Étape 4 : DÉPENSES + lecture BUDGET (committé)
- `DepenseController@store` : `POST /budgets/{budget}/depenses` — charge **`charge_hors_stock`** (type + `reception_id=null` forcés serveur). `Depense` model + factory.
- `BudgetController` enrichi : `indexForSite` (`GET /sites/{site}/budgets`), `showForSiteMonth` (`GET /sites/{site}/budgets/{YYYY-MM}`, 404 si absent). `withSum` pour éviter le N+1.
- `Budget` : `depenses()`, `montantConsomme()`, `montantRestant()`, `pourcentageConsomme()`, `niveauAlerte()` (`aucun`/`seuil_75`/`seuil_90`/`depassement` ; seuils = interrupteurs ; alloué=0 → dépassement si consommé>0).
- `BudgetResource` enrichie (`montant_consomme`, `montant_restant`, `pourcentage_consomme`, `niveau_alerte`, `depenses[]`). `DepenseResource`. Permission `depense.*`.
- Les dépenses `achat_fournisseur` (réception) remontent dans `montant_consomme` sans modif.
- Tests : `tests/Feature/DepenseBudgetTest.php`.

### 🟦 Lot 3 — Étape 1 : SOUS-RECETTES (committé)
- Migrations `sous_recettes` + `lignes_sous_recette` (unique `(sous_recette_id, matiere_premiere_id)`).
- `SousRecetteService` : `creer`/`modifier` (sync complet des lignes), recalcul `cout_matiere = Σ quantite × PMP MOYEN multi-site` (indicateur cuisine, pas une valo comptable).
- `destroy` interdit si utilisée dans une fiche technique active (point d'extension `SousRecette::estUtiliseeDansFicheTechnique()` **désormais câblé**).
- Controller/Resources/Requests (`distinct` matière, `quantite>0`), `SousRecettePolicy` + permission `sousrecette.*`, `SousRecetteException`.
- Tests : `tests/Feature/SousRecetteTest.php`.

### 🟦 Lot 3 — Étape 2 : FICHES TECHNIQUES + table PRODUITS (committé)
- **`produits`** : migration + modèle minimal (id, categorie_id, libelle, statut) + factory. **PAS de controller/service** → viendra au **Module Salle**.
- Migrations `fiches_techniques`, `lignes_fiche_technique`, `lignes_fiche_technique_sous_recette`. Enum `StatutFicheTechnique` (`active`|`archivee`).
- `FicheTechniqueService` : `creer`/`modifier` (**versioning** : archive l'active, crée v+1 active ; atomique ; historique figé), `recalculerCoutMatiere`, `recalculerDepuisSousRecette`.
- `cout_matiere` = **coût de RÉFÉRENCE** (jamais le PMP) : matière = `quantite × MIN(prix_unitaire/qte_par_unite_achat)` catalogue (0 si hors catalogue) ; sous-recette = `cout_matiere/quantite_produite × quantite`.
- **Cascade** : `SousRecetteService` reçoit `FicheTechniqueService` par injection et appelle `recalculerDepuisSousRecette()` après son propre recalcul.
- Controller (`index` filtre `?produit_id`, `show`, `store`, `update` alias, `destroy`, `historique`), Resources, FormRequests, `FicheTechniquePolicy` + permission `fichetechnique.*`, `FicheTechniqueException`.
- `destroy` : fiche archivée → 409 ; fiche active référencée dans une carte active → point d'extension `FicheTechnique::estReferenceeDansCarteActive()` (renvoie `false`, à câbler au Module Salle).
- Tests : `tests/Feature/FicheTechniqueTest.php`.

### 🟦 Lot 3 — Étape 3 : INVENTAIRES (committé)
- Migrations `inventaires` + `lignes_inventaire` (unique `(inventaire_id, matiere_premiere_id)`). Enum `StatutInventaire` (`en_cours`|`cloture`).
- `InventaireService` : `creer` (un seul `en_cours` par site → 409 sinon), `ajouterOuModifierLigne` (théorique = stock courant indicatif), `supprimerLigne`, `cloturer`.
- **Clôture** atomique : lecture stock réel **sous `lockForUpdate`** AVANT correction → `StockService::correctionInventaire(...)` (référence `lignes_inventaire`), `ecart = physique − théorique`, théorique figé, puis `statut=cloture`, `valeur_totale_stock = Σ physique × pmp`, `user_id` = clôtureur. Matière orpheline gérée par `firstOrCreate`.
- Lignes d'un inventaire clôturé = **immuables** (guard service, 409). `destroy` : en cours sans lignes seulement (409 sinon).
- **Rapport** `GET /inventaires/{id}/rapport` : `nb_matieres_inventoriees`, `nb_matieres_site`, `taux_couverture`, `valeur_totale_stock` (null si en cours), lignes + écarts.
- Controller/Resources/Requests (`quantite_physique ≥ 0`), `InventairePolicy` + permission `inventaire.*`, `InventaireException`.
- Tests : `tests/Feature/InventaireTest.php`.

### 🟩 Données de démo & correctifs (committé)
- **`inresto:demo` (`SeedDemoData`) enrichie** : sous-recettes, produits + fiches techniques, **commandes fournisseur envoyées → réceptions confirmées** (dépenses + entrées stock via les services), charges manuelles, **inventaires clôturés** par site. Test de fumée `tests/Feature/SeedDemoDataTest.php`.
- **Bug latent corrigé** : `protected $morphClass` n'est PAS lu par Eloquent. Remplacé par un **morph map** centralisé dans `AppServiceProvider::boot()` (`Relation::morphMap([...])`, NON enforcé pour ne pas casser le `tokenable` de Sanctum) → `mouvements_stock.reference_type` vaut bien `lignes_reception` / `lignes_inventaire`.

---

## 3. Décisions de conception à NE PAS oublier

1. **StockService = unique gardien du stock**, par couple (matière, site). Quantités déjà en unité de stockage quand elles y entrent.
2. **Unités = étiquettes** ; seule conversion = `qte_par_unite_achat` à la réception. PMP/coûts en `decimal(12,4)` ou `(10,2)` selon table, quantités en `(*,3)`.
3. **Commande ≠ stock** ; **réception = seul déclencheur d'entrée de stock** ; **inventaire = seul déclencheur de correction**.
4. **Coût sous-recette = PMP moyen multi-site** (indicateur). **Coût fiche technique = meilleur prix catalogue** (référence). Deux notions distinctes, volontairement.
5. **Fiche technique versionnée** : jamais modifier une archivée ; modif = archive + nouvelle version. Cascade sous-recette → fiches actives.
6. **Migrations éditées en place** quand pertinent (BD reconstruite via `migrate:fresh`) ; altérations OK (précédents : `qte_par_unite_achat`, `receptions.site_id`).
7. **Seeders = défauts réels** (`db:seed`), **démo = factories via `inresto:demo`** — à enrichir à chaque module.
8. **Réponses API** : enveloppe `ApiResponse` `{status, message, data}`. `destroy` renvoie 200 via `ApiResponse::deleted()`.
9. ~~**Points d'extension en attente du Module Salle**~~ → **CÂBLÉS** (§6) : `FicheTechnique::estReferenceeDansCarteActive()` interroge désormais `carte_produit` ; CRUD/API `produits` livré. **Prix de vente porté par `carte_produit`, jamais par le produit** (décision structurante, cf. `NOTES-DE-GESTION.md`).
10. **Sites = périmètre utilisateur** (relation `user_site`, `est_principal`). La liaison historique user↔point de vente est **obsolète** : tout passe par le site. Le sélecteur d'opérations lit le **périmètre** de l'utilisateur, pas tous les sites.

---

## 4. SESSION COURANTE — Sites exposés + CRUD (backend, NON committé)

> Motivation : le front a besoin de la liste des sites (toutes les opérations sont rattachées à `site_id`), or rien ne l'exposait. Validé avec l'utilisateur : implémenter le manque backend.

- **`AuthController::transformUser`** renvoie désormais `sites[]` (id, nom, code, statut, **`est_principal`** via pivot) dans `/auth/me` ET au login. `SiteResource` expose `est_principal` via `whenPivotLoaded('user_site', …)`.
- **`SiteController`** = ressource complète + méthode `perimetre` :
  - `GET /api/sites/perimetre` → **sites actifs de l'utilisateur connecté** (principal en tête). **AUCUNE permission** (périmètre = ses propres sites). → alimente le **sélecteur d'opérations** du front.
  - `apiResource sites` (`GET /api/sites` = TOUS les sites pour la gestion, `store/show/update/destroy`) protégé par **`SitePolicy`** (`site.view/create/update/delete`).
  - ⚠️ Routes : `sites/perimetre` déclarée **AVANT** `apiResource` (sinon captée par `sites/{site}`).
  - `destroy` renvoie **409** si le site porte des points de vente / budgets / stock (intégrité).
- **`StoreSiteRequest` / `UpdateSiteRequest`** : `nom` requis, `code` unique ≤20, `adresse` nullable, `statut` enum.
- **`PermissionSeeder`** : ajout de `'site' => [view, create, update, delete]`. ⚠️ **À exécuter sur la BD** : `php artisan db:seed --class=PermissionSeeder` (idempotent) puis rattacher aux rôles.
- Tests : `tests/Feature/SiteTest.php` (périmètre scoping, `/auth/me`, CRUD, 409 dépendances). **160 tests verts.**

---

## 5. SESSION COURANTE — Front Angular câblé (NON committé)

> Dépôt **séparé** : `../inresto-app` (`/Users/bko/Projets/Innov-Immobilier/InResto/inresto-app`), **Angular 21**. Build : `npm run build`. Guide de navigation : `GUIDE_Structure_Navigation_InnResto.md` dans ce dépôt.

**Conventions front** : composants standalone, `OnPush`, **signals**, `ReactiveFormsModule`, `takeUntilDestroyed` ; services `core/api/<module>/` (`HttpClient` + `map(r => r.data.<clé>)`, enveloppe `ApiResponse<T>`) ; décimaux reçus en **chaînes** ; pattern **drawer** pour le CRUD ; design-system global (`card`, `data-table`, `drawer*`, `field*`, `badge-*`, `btn-*`, `empty-state`) ; onglets via `param-tabs` + `routerLinkActive` ; garde-fous via `AuthService.hasPermission()`.

**Pièges rencontrés (résolus)** :
- Noms de permissions backend **hétérogènes** : `commande-fournisseur.*`, `matiere-premiere.*`, `point-de-vente.*`, `site.*` (tirets) mais `fichetechnique.*`, `sousrecette.*`, `reception.*`, `inventaire.*`, `depense.*` (collés). `menu.config.ts` corrigé.
- Pas de `Number()` ni de globaux dans les templates Angular → passer par une méthode du composant.
- Le budget front utilisait `point_de_vente_id` (obsolète) → migré vers `site_id` + champs de consommation.

**Modules livrés** (par priorité métier) :
- **Fondations** : `core/api/site` (service `perimetre()` vs `list()`), `core/site/selected-site.service` (site courant partagé, persisté localStorage), `AuthUser.sites`, routes + menu.
- **Achats** : catalogue enrichi (`qte_par_unite_achat`) ; **commandes fournisseur** (`/cuisine/commandes` liste + fiche détail, machine à états envoyer/annuler, lignes en brouillon) ; **réceptions** (flux 2 temps depuis la fiche commande) ; **budgets** réécrits sur `site_id` (jauge consommation + `niveau_alerte`) + **dépenses** (charge hors stock).
- **Recettes** (`/cuisine/fiches-techniques`, onglets) : **sous-recettes** (CRUD lignes) ; **fiches techniques** (versioning + historique). ⚠️ choix produit par **identifiant** (placeholder, pas d'API produits).
- **Inventaires** (`/cuisine/stock`, 3 onglets) : **Inventaires** fonctionnel (création, saisie, clôture, rapport) ; onglets « État du stock » / « Mouvements » = **placeholder** (endpoints backend absents).
- **Paramètres** : nouvel onglet **Sites** (CRUD) ; **Points de vente** complété avec `site_id`.

**Sélecteur de site** : opérations (budget, inventaire, réception) → `SiteService.perimetre()` ; gestion (PDV, page Sites) → `SiteService.list()`.

---

## 6. SESSION COURANTE — Module Salle / Lot A : Produits & Carte-Produit (NON committé)

> Démarrage du **Module Salle**. Livre le CRUD des produits, la gestion des
> produits sur une carte (pivot `carte_produit`) et le câblage des points
> d'extension laissés ouverts par le Lot 3. **178 tests verts, Pint clean.**

- **Migration `produits` éditée EN PLACE** (convention #6) : ajout `description`,
  `est_menu`, `photo`, `allergenes`, `softDeletes()`. **Nouvelle migration
  `carte_produit`** : `carte_id` (cascade), `produit_id` (restrict), `prix_vente`
  `decimal(10,2)`, `statut` (actif/inactif), **unique `(carte_id, produit_id)`**.
- **Règle d'or** : le **prix de vente vit sur `carte_produit`, pas sur le produit**
  (un même produit a des prix différents selon la carte). Cf. `NOTES-DE-GESTION.md`.
- **`Produit`** : `SoftDeletes`, relation `cartesProduits()`, méthode
  `estReferenceeDansFicheActive()` (fiches `statut = active`).
- **`ProduitService`** : `creer`/`modifier`/`supprimer`. Suppression = soft delete
  **refusée (409)** si le produit est en fiche technique active (`ProduitException`).
- **`ProduitController`** (`apiResource produits`) : `index` filtres `?categorie_id`
  + `?statut` (paginé), CRUD complet, `destroy` → `ApiResponse::deleted()`.
- **`CarteProduitController`** sous **`/api/cartes/{carte}/produits`** (index, store,
  update, destroy ; param `{produits}` → `carte_produit`) :
  - `store` : produit doit être **actif** (sinon 422), **unicité** par carte (422) ;
  - `update` : `prix_vente` et/ou `statut` (sert à **désactiver** une ligne) ;
  - `destroy` : **409 si la carte est active** (`CarteProduitException`) — on
    désactive plutôt que supprimer sur une carte servie ; OK si brouillon/archivée.
- **`FicheTechnique::estReferenceeDansCarteActive()` CÂBLÉ** : `true` ssi une ligne
  `carte_produit` active du produit appartient à une carte `active`. Le test
  « destroy fiche → 409 quand produit sur carte active » passe au vert.
- Resources (`ProduitResource` enrichie + `categorie`, `CarteProduitResource`),
  4 FormRequests, `ProduitPolicy`/`CarteProduitPolicy`, `CarteProduitFactory`.
- **`PermissionSeeder`** : ajout `produit.*` et `carte_produit.*` (⚠️ underscore).
  **À exécuter sur la BD** : `php artisan db:seed --class=PermissionSeeder` puis
  rattacher aux rôles.
- Exceptions `ProduitException` / `CarteProduitException` rendues par `bootstrap/app.php`.
- Tests : `tests/Feature/ProduitTest.php`, `tests/Feature/CarteProduitTest.php`.

---

## 7. SESSION COURANTE — Module Salle / Lot B : Tables & Sessions de caisse (NON committé)

> Suite du **Module Salle**. Livre les tables de salle (CRUD par PDV) et les
> sessions de caisse (ouverture/fermeture avec contrôle d'écart). **198 tests
> verts, Pint clean.**

- **Migrations** : `tables_salle` (PDV, `numero`, `capacite`, `statut` enum,
  **unique `(point_de_vente_id, numero)`**) ; `sessions_caisse` (`caisse_id`,
  `user_id` **foreignUuid**, `ouvert_le` useCurrent, `fonds_initial`, `ferme_le`,
  `montant_theorique`/`montant_reel`/`ecart`, `statut` enum, `notes`).
- **Une seule session OUVERTE par caisse** : garanti par un **index unique
  PARTIEL** `CREATE UNIQUE INDEX ... WHERE statut = 'ouverte'` (syntaxe valable
  PostgreSQL **ET** SQLite → fonctionne aussi en test), **doublé** d'un contrôle
  applicatif `lockForUpdate` dans le service (renvoie un 409 propre).
- Enums `StatutTable` (libre/occupee/reservee/a_debarrasser), `StatutSessionCaisse`
  (ouverte/fermee).
- **`TableSalle`** : `pointDeVente()`, point d'extension **`aDesCommandes()` → false**
  (commandes_client absent). **`SessionCaisse`** : `caisse()`, `user()`, point
  d'extension **`montantPaye()` → 0** (table paiements absente).
- **`TableSalleService`** : `creer`/`modifier`/`supprimer` (garde-fou
  `aDesCommandes()` → suppression autorisée pour l'instant) / `changerStatut`.
- **`SessionCaisseService`** : `ouvrir` (refus 409 si déjà ouverte),
  `fermer` (`montant_theorique = fonds_initial + montantPaye()`,
  `ecart = reel − theorique`, `ferme_le = now`, statut fermee). **2 points
  d'extension documentés (TODO Lot C)** : `montantPaye()` réel + contrôle d'accès
  via `caisse → PDV → site` croisé `user_site` + permission `session_caisse.utiliser`.
- **`TableSalleController`** : `apiResource tables` (param `{table}`) + `PATCH
  tables/{table}/statut`. **`SessionCaisseController`** : `apiResource
  sessions-caisse` (index `?caisse_id`, store=**ouvrir**, show) + `POST
  sessions-caisse/{session}/fermer`.
- Resources (`TableSalleResource` + PDV, `SessionCaisseResource` + caisse→PDV→site
  + ouvreur inline), FormRequests (`montant_reel` **requis**, 0 accepté),
  Policies, Factories.
- **`PermissionSeeder`** : ajout `table.*` et `session_caisse.*` (dont
  **`session_caisse.utiliser`** réservé au Lot C). ⚠️ **À exécuter sur la BD** :
  `php artisan db:seed --class=PermissionSeeder` puis rattacher aux rôles.
- Exceptions `TableSalleException` / `SessionCaisseException` rendues par `bootstrap/app.php`.
- Tests : `tests/Feature/TableSalleTest.php`, `tests/Feature/SessionCaisseTest.php`.

---

## 8. Où on s'est arrêté — PROCHAINES ÉTAPES (backend)

1. **Module Salle — Lot C (gros morceau)** : **commandes client** (rattachées à une
   `table_salle` + une `session_caisse` ouverte), **paiements**, puis **déstockage à
   la vente** (lignes de vente → `StockService::sortie`). Ce lot **câble les points
   d'extension** laissés ouverts : `TableSalle::aDesCommandes()`,
   `SessionCaisse::montantPaye()` (→ `montant_theorique` réel), et le **contrôle
   d'accès** « utiliser une session » (`caisse → PDV → site` × `user_site` ×
   permission `session_caisse.utiliser`). Reste aussi la logique **menus**
   (`menu_produit`, au-delà du champ `est_menu`).
   ~~CRUD `produits`, `carte_produit`, `estReferenceeDansCarteActive()`~~ → **FAIT (§6)**.
   ~~Tables + sessions de caisse~~ → **FAIT (§7)**.
2. **Endpoints lecture stock par site** (`stock_courant`/`pmp`) + **mouvements de stock** — toujours pas exposés. **Débloque les onglets placeholder « État du stock » / « Mouvements » du front.**
3. ~~CRUD `/api/sites` + périmètre utilisateur~~ → **FAIT** (§4).
4. PDF bon de commande (CDC 3.1.4) — reporté (moteur à trancher : dompdf vs Browsershot).
