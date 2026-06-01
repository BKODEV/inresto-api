# 🔁 Passation de session — InResto API (TEMPORAIRE, à supprimer)

> Contexte pour reprendre le développement à la prochaine session Claude Code :
> ce qui a été ajouté et où on s'est arrêté.
>
> État git : `HEAD = 7dbc49c` (committé) sur la branche
> **`feat/multi-site-stock-commande`** — regroupe les modules stock,
> commande fournisseur, le refactor multi-site et le périmètre utilisateur par
> site. À fusionner dans `main` quand validé.

---

## 1. Contexte projet

- API Laravel 13 / PHP 8.4, auth **Sanctum** (tokens Bearer), permissions **spatie/laravel-permission** (guard `api`).
- BD : **PostgreSQL**. Users en **UUID** → toute FK vers `users` = `foreignUuid` (pas `foreignId`).
- Domaine : gestion de restauration (stock, achats fournisseurs, budgets, paramètres).
- Conventions : controllers fins + **Services** pour le métier, **FormRequests** pour la validation, **API Resources** pour les réponses, **Policies** (permission `feature.action`), réponses via `App\Helpers\ApiResponse`.
- ⚠️ **Tests** : la config en cache force `pgsql`. Toujours lancer `php artisan config:clear` avant `php artisan test` (sinon les tests tapent sur pgsql au lieu du sqlite `:memory:` de `phpunit.xml`).

---

## 2. Fonctionnalités ajoutées

### 🟦 Lot 1 (committé dans `81085e0`) — CRUD de base + socle stock
CRUD complets pour : `fournisseurs`, `matieres-premieres`, `catalogue-fournisseur`
(+ `sync`), `budgets`, `points-de-vente`, paramètres (`caisses`, `cartes`,
`categories-produit`, `categories-matiere-premiere`, `modes-de-paiement`,
`unites-de-mesure`), auth.

### 🟩 Module STOCK — StockService
- `app/Enums/MouvementType.php` — entree_reception, sortie_vente, perte, casse, offert, repas_personnel, correction_inventaire + `sens()`.
- `app/Exceptions/StockException.php`
- `app/Models/MouvementStock.php` — `quantite` toujours positive, relation polymorphe `reference()`, `timestamps=false`, **`site_id`**.
- `app/Services/StockService.php` — **SEUL** point qui écrit `mouvements_stock` et modifie `stock_courant`/`pmp`. Opère désormais sur le couple **(matière, site)** : `entree()`, `sortie()`, `correctionInventaire()` prennent un **`site_id`**. Transaction + `lockForUpdate` sur la ligne `stocks` (créée à la volée via `firstOrCreate` si absente), recalcul PMP inchangé.
- `config/inresto.php` — `inresto.stock.autoriser_stock_negatif` (défaut `true` ; une vente n'est jamais bloquée).
- `tests/Feature/StockServiceTest.php` + `tests/Feature/CuisineStockTest.php`.
- ❗ Pas d'endpoint API stock : le StockService est interne, appelé par la future réception.

### 🟩 Refactor MULTI-SITE
Règle d'or : **DÉFINITIONS PARTAGÉES, OPÉRATIONS RATTACHÉES.**
- **Partagé** (global, aucun `site_id`) : `matieres_premieres`, `fournisseurs`, `catalogue_fournisseur`, `unites_de_mesure`, catégories.
- **Par site** : stock, mouvements de stock, budget, inventaire.
- **Par PDV** : ventes (à venir). Un PDV appartient à exactement un site.
- `app/Models/Site.php` — `pointsDeVente()`, `stocks()`, `budgets()`, `mouvements()`, `users()`.
- `app/Models/Stock.php` — table `stocks` : `(matiere_premiere_id, site_id)` unique, `stock_courant` decimal(12,3) + `pmp` decimal(12,4), défauts 0. **Cache du stock par site** (déplacé hors de `matieres_premieres`, qui ne porte plus stock_courant/pmp).
- `points_de_vente.site_id`, `budgets.site_id` (unique `(site_id, mois)`), `mouvements_stock.site_id`.
- Resources/Requests mis à jour : Budget (`point_de_vente_id` → `site_id`), PointDeVente (+`site_id`), MatierePremiere (retrait stock_courant/pmp), `SiteResource`.

### 🟩 Périmètre UTILISATEUR = SITE
- Migration pivot **re-datée** `2026_06_01_120000_create_user_site_table.php` (doit s'exécuter après `sites`). Table `user_site` : `user_id` (uuid), `site_id`, **`est_principal`** (bool), unique `(user_id, site_id)`, pas de timestamps.
- L'ancien pivot user↔PDV (`users_points_de_vente`) était **orphelin** → supprimé.
- `User::sites()` / `Site::users()` en belongsToMany `user_site` + `withPivot('est_principal')`. Le site d'un PDV reste déductible via `PointDeVente::site()`.
- `tests/Feature/UserSiteTest.php` (multi-sites + principal, relation inverse, unicité, détachement).

### 🟩 Module COMMANDE FOURNISSEUR — CRUD + machine à états
- `app/Enums/StatutCommandeFournisseur.php` — `brouillon → envoyee → partiellement_recue → cloturee` ; `* (sauf cloturee) → annulee`.
- `app/Services/CommandeFournisseurService.php` — création + lignes, **recalcul auto** du `montant_previsionnel`, **numéro auto** `CF-AAAAMM-####` (séquence/mois), transitions d'état.
- `app/Policies/CommandeFournisseurPolicy.php` + permission `commande-fournisseur.*`.
- Controllers + 4 FormRequests + 2 Resources + routes + `tests/Feature/CommandeFournisseurTest.php`.
- Règles : modification (entête + lignes) uniquement en **brouillon** ; `envoyee` exige ≥1 ligne ; matière/unité référencées doivent être **actives** ; commande ne touche **jamais** le stock.

### 🟩 Conversion d'unité d'achat
- `qte_par_unite_achat` sur `catalogue_fournisseur` (`decimal 10,3`, défaut 1, requis et `> 0` à l'API). Propagé : modèle, factory, resource, requests, controller `sync`, tests.

### 🟩 Seeders & données de démo
- **Seeders** (`php artisan db:seed`) = données par défaut RÉELLES, **sans factory** : `PermissionSeeder`, `SiteSeeder` (site `PRINCIPAL`), `SuperAdminSeeder` (super-admin rattaché à PRINCIPAL, `est_principal=true`). Ordre : SiteSeeder **avant** SuperAdminSeeder.
- **Données de démo** = via factories, dans la commande **`php artisan inresto:demo`** (`app/Console/Commands/SeedDemoData.php`), enrichie à chaque module. **Plus aucune factory dans les seeders** (`DemoSeeder` supprimé).
- Workflow local : `migrate:fresh` → `db:seed` → `inresto:demo`.

### 📄 Docs
- `NOTES-DE-GESTION.md` (document vivant ; section "Unités de mesure & conversion d'achat").
- `_PASSATION_SESSION.md` (ce fichier, temporaire).

**État : 73 tests passent (181 assertions), Pint clean.**

---

## 3. Décisions de conception à NE PAS oublier

1. **StockService = unique gardien du stock**, par couple **(matière, site)**. Aucun controller/service n'écrit `mouvements_stock`, `stock_courant`, `pmp` en direct.
2. **Quantités déjà en unité de stockage** quand elles entrent dans le StockService. Il ne convertit rien.
3. **Unités de mesure = étiquettes**, pas de conversion dimensionnelle. Une matière = **une** unité (stockage = usage). Seule conversion = `qte_par_unite_achat` à la réception (cf. `NOTES-DE-GESTION.md`).
4. **PMP / coûts unitaires en `decimal(12,4)`**, quantités en `decimal(12,3)`.
5. **Commande ≠ stock** : une commande fournisseur ne touche jamais le stock. Le stock bouge uniquement à la réception.
6. `partiellement_recue` / `cloturee` sont **pilotés par la réception** (méthodes `marquerPartiellementRecue`/`marquerCloturee` exposées mais non câblées dans le module commande).
7. **Périmètre utilisateur = site** ; ne JAMAIS réintroduire de scoping par point de vente au niveau utilisateur.
8. **Migrations éditées en place** (pré-prod, BD reconstruite via `migrate:fresh`) ; pas de migrations de rename empilées.
9. **Seeders = défauts réels uniquement**, données de démo via `inresto:demo`.

---

## 4. Où on s'est arrêté — PROCHAINE ÉTAPE : module RÉCEPTION

Migrations `receptions` et `lignes_reception` **déjà posées** (à vérifier comme pour les autres lots). À coder :

1. **Service de réception** qui, pour chaque ligne reçue, applique la **conversion** (seul endroit du système) :
   ```
   quantite_stockage      = quantite_recue × catalogue.qte_par_unite_achat
   prix_par_unite_stockage = prix_unitaire_reel ÷ catalogue.qte_par_unite_achat
   ```
   puis appelle `StockService::entree(matiere, SITE, quantite_stockage, prix_par_unite_stockage, MouvementType::EntreeReception, userId, reference: $reception)`.
   ⚠️ **La réception porte un `site_id`** (le stock entre sur le site de la réception) — à intégrer au schéma/Service réception.
2. **Transitions de la commande liée** : réception partielle → `marquerPartiellementRecue()` ; complète → `marquerCloturee()`.
3. **Câbler `aDesReceptions()`** dans `CommandeFournisseurService` (point d'extension déjà prévu) pour bloquer l'annulation d'une commande déjà réceptionnée.
4. CRUD/endpoints + FormRequests + Resources + Policy + permission `reception.*` + tests.

### Décisions ouvertes / en attente
- **Endpoint(s) stock par site** à concevoir (lecture `stock_courant`/`pmp` par site).
- **CRUD `/api/sites`** + exposition du périmètre utilisateur (sites de l'user) : à créer selon besoins (aujourd'hui les sites viennent des seeders/démo, pas d'API).
- **PDF bon de commande (CDC 3.1.3)** : moteur retenu = **Browsershot** (à reprendre plus tard ; implique Node+Puppeteer+Chromium côté serveur, infra non provisionnée). À isoler derrière un contrat `PdfGenerator`. Stockage : générer à la volée en brouillon, figer/persister à l'envoi (table `documents` polymorphe envisagée).
- `destroy` d'une commande n'est permis qu'en `brouillon` (choix à confirmer).
