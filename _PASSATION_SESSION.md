# 🔁 Passation de session — InResto API

> **But** : permettre à une **nouvelle session Claude Code** de démarrer avec une
> bonne base de contexte — le **contexte projet**, l'**état global** et les
> **points d'attention**.
> **Ce n'est pas un journal** : l'historique des modifications vit dans git, le
> détail de chaque module dans le code, et les décisions structurantes dans les
> **mémoires**. On met ce fichier à *jour* (on remplace l'état), on ne l'*empile* pas.

## Contexte projet

- API **Laravel 13 / PHP 8.4**, auth **Sanctum** (Bearer), permissions **spatie**
  (guard `api`), BD **PostgreSQL**. Users en **UUID** → FK users = `foreignUuid`.
- Domaine : restauration **multi-site** (stock, achats, budgets, recettes,
  inventaires, salle/vente, reporting).
- Conventions : controllers fins + **Services** (métier) + **FormRequests**
  (validation) + **API Resources** + **Policies** (`feature.action`) ; réponses
  via `App\Helpers\ApiResponse` `{status,message,data}` ; routes sous **`/api/`**
  (pas de `/v1/`) ; exceptions métier dédiées rendues en JSON par `bootstrap/app.php`.
- ⚠️ **Tests** : `php artisan config:clear` avant `php artisan test` (le cache
  force pgsql sinon). **Pint** : `vendor/bin/pint --dirty --format agent` avant de finaliser.
- Front séparé : **`../inresto-app`** (Angular 21, zoneless, signals). Build `npm run build`.

## État global

- **359 tests verts, Pint clean.** Dernier commit `main` : `975bfff`.
  ⚠️ **Changements en cours non commités** : seeders de référentiels d'init
  (catégories produit/MP, unités, matières premières, 3 fournisseurs) branchés
  dans `DatabaseSeeder` ; garde-fou catalogue (interdit de retirer une matière
  sous commande en attente de réception) ; **cohérence unité de ligne estampée
  serveur partout** — fiches techniques & sous-recettes (= unité de stockage
  matière) et commande fournisseur & réception (= unité d'achat du catalogue,
  celle qui porte le facteur PMP) ; fronts en lecture seule. NOTES-DE-GESTION.md
  aussi enrichi (vue d'ensemble, utilisateurs/rôles, machines à états, reporting).
- Modules livrés (back testé + front câblé) :
  - **Socle & multi-site** : sites, points de vente, caisses, périmètre user par site.
  - **Achats** : fournisseurs, catalogue, commandes fournisseur, réceptions,
    budgets & dépenses.
  - **Stock** : `StockService` (PMP), inventaires.
  - **Recettes** : sous-recettes, fiches techniques versionnées.
  - **Salle / vente** : produits & cartes, tables, sessions caisse, commandes
    client + KDS + déstockage, paiements / remises / annulations / clôture.
  - **Paramètres d'établissement + Logo + PDF** : 1 fiche/site, upload logo
    (disque `public`) ; `PdfService` + 4 documents (bon de commande, facture &
    reçu client, fiche technique) via dompdf, stream inline jamais stocké.
  - **Utilisateurs & Rôles** : RBAC admin complet (hiérarchie anti-escalade,
    périmètre, reset mots de passe), commande `inresto:install`.
  - **Reporting** (lecture seule) : ventes, food cost, stock, budget, dashboard.

## Décisions structurantes

Elles vivent dans les **mémoires** (voir plus bas). À retenir en tête :
- **Super-admin = périmètre « tous les sites »** (bypass, pas de rattachement
  `user_site`) → [[module-utilisateurs-roles]].
- **PDF orientés revente** : zéro donnée d'établissement en dur (test anti-régression
  grep « Innov » interdit dans `resources/views/pdf/**`) ; doc client = « FACTURE »
  + mention non-fiscale ; en-tête bon de commande = `?site_id=` sinon site `PRINCIPAL` ;
  coût fiche technique = meilleur prix catalogue (miroir lecture seule).

## Points d'attention

1. **Prérequis déploiement / usage réel** :
   - `php artisan migrate` puis `php artisan storage:link` (servir les logos).
   - **php.ini prod** : `post_max_size` = `upload_max_filesize` = **8M** (sinon
     upload logo coupé au *Request Startup* avant Laravel — cf. dev Herd déjà à 8M).
   - **SMTP** + `FRONTEND_URL` configurés (reset mots de passe par email).
2. **Lecture stock par site** (`stock_courant`/`pmp`) + **mouvements** : non
   exposés en API → prévu pour le **module Pilotage**.
3. **Carte** : `CarteService` + index unique partiel « une seule carte active par
   PDV » (aujourd'hui app-level via `UpdateCarteRequest`). Pas urgent.
4. **Front `../inresto-app`** : repo séparé — vérifier son état de commit/build.

## Mémoires liées (décisions structurantes)

`architecture-multi-site` · `module-utilisateurs-roles` · `module-reporting` ·
`pmp-decimal-precision` · `unites-de-mesure-etiquettes` · `convention-seeders-vs-demo`
· `carte-organisation-categories` · `commande-fournisseur-catalogue` · `front-app-inresto`.
