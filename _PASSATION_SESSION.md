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
- ⚠️ **Tests** : `php artisan config:clear` avant `php artisan test` si un cache de
  config traîne (il force pgsql sinon ; la suite tourne en SQLite `:memory:`).
  La `memory_limit` est fixée à 512M dans `phpunit.xml` — la suite dépassait les
  128M par défaut. **Pint** : `vendor/bin/pint --dirty --format agent` avant de finaliser.
- Front séparé : **`../inresto-app`** (Angular 21, zoneless, signals). Build
  `npm run build`, tests `npm test` (Vitest + jsdom, **pas** Jasmine).

## État global

- **442 tests verts, Pint clean.** Arbre propre, rien en attente de commit.
- **Dépôts distants configurés** (premier push fait, `main` suit `origin/main`) :
  - API → `git@github.com:BKODEV/inresto-api.git`, HEAD `1c52acb`.
  - Front → `git@github.com:BKODEV/inresto-app.git`, HEAD `8848cf9`.
- Modules livrés (back testé + front câblé) :
  - **Socle & multi-site** : sites, points de vente, caisses, périmètre user par
    site — **appliqué par le serveur** (voir Décisions structurantes).
  - **Achats** : fournisseurs, catalogue, commandes fournisseur, réceptions,
    budgets & dépenses. Un article ne figure qu'une fois par commande et par
    réception ; `quantite_recue` / `quantite_restante` exposées.
  - **Stock** : `StockService` (PMP), inventaires, **lecture par site
    (`GET /api/stock`) et journal des mouvements (`GET /api/stock/mouvements`)**
    sous permission `stock.view`.
  - **Recettes** : sous-recettes, fiches techniques versionnées.
  - **Salle / vente** : produits & cartes, tables, sessions caisse, commandes
    client + KDS + déstockage, paiements / remises / annulations / clôture.
    Ajout **en lot** de produits sur une carte (`POST /cartes/{carte}/produits/lot`).
  - **Produits** : photo par produit (téléversement dédié, disque `public`),
    affichée sur le menu public.
  - **Menu public par QR** : jeton **par table**, seul périmètre non authentifié
    (`GET /api/public/menu/{token}`), QR par table et planche par point de vente.
  - **Paramètres d'établissement + Logo + PDF** : 1 fiche/site, upload logo ;
    `PdfService` + 4 documents (bon de commande, facture & reçu client, fiche
    technique) via dompdf, stream inline jamais stocké.
  - **Utilisateurs & Rôles** : RBAC admin complet (hiérarchie anti-escalade,
    périmètre, reset mots de passe), commande `inresto:install`.
  - **Reporting** (lecture seule) : ventes, food cost, stock, budget, dashboard.
- **Données de démo** : `inresto:demo` regénère un jeu complet (factories) ;
  `inresto:demo-carte` est **additive et idempotente** — carte internationale,
  matières et prix catalogue manquants, fiches techniques, photos, puis
  approvisionnement du stock par commande fournisseur réceptionnée.

## Décisions structurantes

Elles vivent dans les **mémoires** (voir plus bas). À retenir en tête :
- **Le pivot `user_site` est une frontière appliquée par le serveur**, pas un
  confort d'affichage : `App\Support\SitePerimetre` borne les lectures,
  `App\Rules\SiteDansPerimetre` les écritures, et les policies Inventaire /
  Reception / Budget contrôlent l'accès direct par identifiant.
  **Deux notions de « global »** : en opérationnel, seul le super-admin dépasse
  son périmètre ; `reporting.global` n'élargit que la lecture analytique
  → [[perimetre-site-applique]].
- **Au moins un site est obligatoire à la création d'un compte** (et on ne peut
  pas vider le périmètre d'un compte existant) — sans quoi l'application du
  périmètre produirait des comptes en 403 permanent.
- **Super-admin = périmètre « tous les sites »** (bypass, pas de rattachement
  `user_site`) → [[module-utilisateurs-roles]].
- **PDF orientés revente** : zéro donnée d'établissement en dur (test anti-régression
  grep « Innov » interdit dans `resources/views/pdf/**`) ; doc client = « FACTURE »
  + mention non-fiscale ; **en-tête pris sur le site d'impression** (`?site_id=`,
  sinon site `PRINCIPAL`) pour le bon de commande **et la fiche technique** ;
  coût fiche technique = meilleur prix catalogue (miroir lecture seule).
- **Front : la portée de site est un tri-état** — « à choisir » (le shell impose
  le choix au premier chargement), un site, ou la vue consolidée « tous mes
  sites ». Les écrans opérationnels refusent le mode consolidé
  → [[perimetre-site-applique]].

## Points d'attention

1. **Prérequis déploiement / usage réel** :
   - `php artisan migrate` puis `php artisan storage:link` (logos **et** photos
     de produits).
   - **php.ini prod** : `post_max_size` = `upload_max_filesize` = **8M** (sinon
     upload logo coupé au *Request Startup* avant Laravel — cf. dev Herd déjà à 8M).
   - **SMTP** + `FRONTEND_URL` configurés (reset mots de passe par email).
   - **`PUBLIC_MENU_BASE_URL`** doit pointer sur le **front**, pas sur l'API :
     à défaut la chaîne de repli retombe sur `APP_URL` et les QR mènent nulle part.
     Ni cette variable ni `FRONTEND_URL` ne figurent dans `.env.example`.
   - Avant d'appliquer le périmètre en prod, **vérifier qu'aucun compte n'est
     orphelin** : `User::doesntHave('sites')->count()` doit valoir 0.
2. **Le site `PRINCIPAL` n'a pas forcément de fiche d'établissement** : tout PDF
   qui passe par le repli sortira alors sans en-tête. Soit on renseigne ses
   paramètres, soit on fait du repli le premier site actif configuré.
3. **Périmètre non appliqué sur `points_de_vente` et `parametre_etablissement`**,
   qui portent aussi un `site_id`. Écrans de configuration réservés à des rôles
   administratifs, donc enjeu moindre — mais ils restent hors de la frontière.
4. **Carte** : `CarteService` + index unique partiel « une seule carte active par
   PDV » (aujourd'hui app-level via `UpdateCarteRequest`). Pas urgent.
5. **Convention de test** : chaque fichier définit son propre helper
   `actingAs…`. Réutiliser un nom déjà pris provoque une **erreur fatale PHP**
   (redéclaration de fonction) — la suite sort alors en code 255 **sans aucune
   sortie**, alors que chaque fichier passe isolément.

## Mémoires liées (décisions structurantes)

`architecture-multi-site` · `perimetre-site-applique` · `module-utilisateurs-roles` ·
`module-reporting` · `module-menu-public-qr` · `pmp-decimal-precision` ·
`unites-de-mesure-etiquettes` · `convention-seeders-vs-demo` ·
`carte-organisation-categories` · `commande-fournisseur-catalogue` · `front-app-inresto`.
