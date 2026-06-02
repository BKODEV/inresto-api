# 🔁 Passation de session — InResto API (TEMPORAIRE, à supprimer)

> Contexte pour reprendre le développement à la prochaine session Claude Code :
> ce qui a été ajouté et où on s'est arrêté.
>
> État git : branche **`main`**. Les Lots 2 & 3 décrits ci-dessous sont
> **committés** (`fc4ea52` « Module cusine & stock ok », par-dessus `5b3e8f0`
> stock multi-site + commande fournisseur). Le dernier commit ajoute le
> correctif morph map, l'enrichissement de `inresto:demo` et les tests associés.
>
> **État : 150 tests passent (370 assertions), Pint clean.**

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
9. **Points d'extension en attente du Module Salle** : `FicheTechnique::estReferenceeDansCarteActive()` (pivot `carte_produit` à créer), CRUD/API `produits`.

---

## 4. Où on s'est arrêté — PROCHAINES ÉTAPES (backend)

1. **Module Salle** (gros morceau) : CRUD/API `produits` (+ prix de vente), pivot `carte_produit`, câblage de `estReferenceeDansCarteActive()` + test « fiche référencée → 409 », et probablement le déstockage à la vente (lignes de vente → `StockService::sortie`).
2. **Endpoints lecture stock par site** (`stock_courant`/`pmp`) — toujours pas exposés.
3. **CRUD `/api/sites`** + exposition du périmètre utilisateur.
4. PDF bon de commande (CDC 3.1.4) — reporté (moteur à trancher : dompdf vs Browsershot).

---

## 5. 📋 PROMPT À COLLER DANS LA SESSION FRONT-END

> Copier-coller tel quel dans la session Claude Code du dépôt **front-end**.

```
# Front InResto — Liaison API des modules Stock/Achats/Recettes/Inventaires

## Contexte
Le front ne couvre aujourd'hui que le CRUD de : paramètres, cartes, budgets,
matières premières, fournisseurs. Le backend (API Laravel) a depuis livré
plusieurs modules. Objectif : développer les écrans correspondants et les câbler
à l'API, en respectant les conventions front déjà en place (structure, store,
client HTTP, gestion auth/permissions).

⚠️ Tu as accès au dépôt backend : n'INVENTE aucun endpoint. Lis-y la vérité —
`routes/api.php` (chemins, noms, sous-routes), les FormRequests `app/Http/Requests/*`
(payloads + règles de validation), les API Resources `app/Http/Resources/*`
(forme exacte des réponses), et les Policies/permissions `app/Policies/*`. En cas
de doute sur un contrat, c'est le backend qui fait foi.

## Conventions transverses (vérifier dans le backend)
- Auth Sanctum, token Bearer (`Authorization: Bearer <token>`). Récupérer les
  permissions ET les sites de l'utilisateur connecté (voir AuthController/me).
- Enveloppe de réponse : `{ status: "success"|"error", message, data }`.
  Validation 422 : `data` remplacé par `errors:{champ:[...]}`. Erreurs métier
  409/422 : afficher `message` tel quel.
- Pagination : `data.<ressource>` + `data.pagination { total, par_page,
  page_courante, derniere_page }`, query `?per_page`/`?page`.
- Permissions au format `feature.action` : masquer/désactiver les actions selon
  les permissions, gérer le 403.
- Multi-site : les opérations rattachées à un site (réception, inventaire,
  budget, dépense) portent un `site_id`. Prévoir un sélecteur de site basé sur
  les sites de l'utilisateur.

## Features à développer (comportement métier ; endpoints à lire dans le backend)

1. **Points de vente** — CRUD (rattaché à un site).
2. **Catalogue fournisseur** — édition par fournisseur (sync d'un tableau) et/ou
   CRUD ; chaque ligne porte un facteur `qte_par_unite_achat`.
3. **Commandes fournisseur** — CRUD + machine à états
   (brouillon → envoyee → partiellement_recue → cloturee ; * sauf cloturee →
   annulee). Modifiable UNIQUEMENT en brouillon ; actions envoyer/annuler ;
   gestion des lignes en brouillon. Numéro et montant prévisionnel calculés côté
   serveur (lecture seule).
4. **Réceptions** — flux en 2 temps : créer l'entête (commande + site), puis
   confirmer avec les lignes reçues. La confirmation met à jour le stock du site,
   crée les dépenses d'achat et fait transiter la commande. Gérer les erreurs
   métier (budget du site absent, facteur catalogue manquant).
5. **Budgets enrichis + dépenses** — vue par site/mois avec consommation
   (montant consommé/restant, pourcentage, niveau d'alerte
   aucun/seuil_75/seuil_90/depassement) et liste des dépenses ; saisie de charges
   hors stock. Afficher une jauge + alertes selon le niveau.
6. **Sous-recettes** — CRUD avec synchronisation complète des lignes (matières).
   Coût matière en lecture (calculé). Suppression bloquée si utilisée par une
   fiche active.
7. **Fiches techniques** — versioning : enregistrer crée la v1 ou archive
   l'active et crée la version suivante. Lignes matières + lignes sous-recettes
   (au moins une au total). Écran d'historique (toutes versions d'un produit),
   affichage version/statut/coût de référence.
   ⚠️ LIMITE BACKEND : pas encore d'endpoint `produits` (arrive au « Module
   Salle »). Prévoir un placeholder pour le choix du produit et SIGNALER ce
   manque — ne pas inventer d'endpoint produits.
8. **Inventaires** — création (un seul « en cours » par site), saisie des lignes
   (quantité physique, théorique indicatif), suppression de ligne, clôture
   (irréversible → corrections de stock), écran de rapport (taux de couverture,
   valeur totale, écarts). Lignes en lecture seule une fois clôturé.

## Attendu
- Couche service/API typée par module (réutiliser le client HTTP et la gestion
  d'enveloppe/erreurs existante), endpoints repris du backend.
- Écrans CRUD + workflows (états commande, confirmation réception, clôture
  inventaire) avec garde-fous UI selon statut et permissions.
- Gestion cohérente des erreurs métier (afficher `message`).
- Respecter les conventions et le design system déjà en place.
- Lister en fin de session les manques backend rencontrés (ex. endpoint
  produits, lecture stock par site).
```
