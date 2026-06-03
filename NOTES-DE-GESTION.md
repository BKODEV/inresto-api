# Notes de gestion — InResto

> Document vivant. On y consigne les **règles métier** et **décisions de conception**
> qui ne se devinent pas à la lecture du code, au fur et à mesure des lots.
> Objectif : qu'un nouvel arrivant (ou nous-mêmes dans 6 mois) comprenne *pourquoi*
> le système est fait comme ça, pas seulement *comment*.

## Sommaire

- [Unités de mesure & conversion d'achat](#unités-de-mesure--conversion-dachat)
- [Qui a le droit d'écrire le stock](#qui-a-le-droit-décrire-le-stock)
- [Deux notions de coût : valorisation (PMP) vs référence (catalogue)](#deux-notions-de-coût--valorisation-pmp-vs-référence-catalogue)
- [Définitions partagées vs opérations rattachées au site](#définitions-partagées-vs-opérations-rattachées-au-site)
- [Versioning des fiches techniques](#versioning-des-fiches-techniques)
- [Prix de vente porté par la carte, pas par le produit](#prix-de-vente-porté-par-la-carte-pas-par-le-produit)
- [Une seule session de caisse ouverte par caisse](#une-seule-session-de-caisse-ouverte-par-caisse)

---

## Unités de mesure & conversion d'achat

### Règle d'or

Les unités de mesure sont de **simples étiquettes** créées librement par les
utilisateurs (`kg`, `g`, `cagette`, `botte`, `louche`…). Le système **ne code
aucune conversion dimensionnelle** entre elles et ne stocke **aucun coefficient
abstrait**. La **seule** traduction d'une unité vers une autre se fait à la
**réception d'un achat**, via un nombre concret saisi par couple
fournisseur/matière.

> **Pourquoi ?** Un coefficient de conversion abstrait saisi à la main
> corromprait silencieusement le PMP (prix moyen pondéré) en cas d'erreur. On
> préfère un nombre concret et local (« 1 cagette = 25 kg chez CE fournisseur »),
> appliqué à un seul endroit du code.

### Les 3 endroits où une unité apparaît

| Niveau | Table / colonne | Quelle unité ? | Qui la fixe |
|---|---|---|---|
| 1. Catalogue d'unités | `unites_de_mesure` (`libelle`, `symbole`) | Étiquettes libres | La cheffe, librement |
| 2. Unité de **stockage** | `matieres_premieres.unite_de_mesure_id` | L'unité de vérité d'une matière. `stock_courant` + `pmp` vivent dedans | La cheffe — **1 seule** par matière |
| 3. Unité d'**achat** | `catalogue_fournisseur.unite_achat_id` + `qte_par_unite_achat` | Comment **ce fournisseur** vend cette matière | Par couple fournisseur/matière |

Points clés :

- **Une matière première = une seule unité** (son unité de stockage). `stock_courant`
  et `pmp` sont toujours exprimés dans cette unité.
- **Unité de stockage = unité d'usage.** Les fiches techniques / recettes
  consommeront la matière dans cette même unité — pas de distinction usage/stockage,
  pas de double conversion interne.
- `catalogue_fournisseur.prix_unitaire` est exprimé **par unité d'achat**
  (ex. prix par cagette), alors que `matieres_premieres.pmp` est **par unité de
  stockage** (ex. par kg). Le passage de l'un à l'autre = la division par
  `qte_par_unite_achat`, à la réception.
- `qte_par_unite_achat` (`decimal 10,3`, défaut `1`, requis et `> 0` à l'API) =
  « combien d'unités de stockage dans 1 unité d'achat de CE fournisseur ». Le
  défaut `1` couvre le cas identité (on achète et on stocke dans la même unité).

### Exemple déroulé : la Tomate, stockée en kg

**Étape 1 — Unités.** La cheffe crée les étiquettes : `kg`, `cagette`. Aucun lien
entre elles dans le système.

**Étape 2 — Matière.** Elle crée *Tomate* avec **unité de stockage = kg**.
Désormais `stock_courant = 100` signifie **100 kg**, et `pmp = 1,28` signifie
**1,28 € / kg**.

**Étape 3 — Catalogue fournisseur.** Le conditionnement varie d'un fournisseur à
l'autre, donc chaque ligne porte son propre facteur :

| Fournisseur | `unite_achat_id` | `prix_unitaire` (par unité d'achat) | `qte_par_unite_achat` |
|---|---|---|---|
| Primeur Dupont | cagette | 30 € / cagette | 25 (1 cagette = 25 kg) |
| Grossiste Martin | kg | 1,40 € / kg | 1 (cas identité) |

**Étape 4 — Réception (la SEULE conversion).** La cheffe reçoit **4 cagettes** de
Primeur Dupont, prix réel facturé **32 €/cagette**. Le module réception applique :

```
Quantité en stockage = quantite_recue × qte_par_unite_achat
                     = 4 × 25 = 100 kg

Prix par unité de stockage = prix_unitaire_reel ÷ qte_par_unite_achat
                          = 32 ÷ 25 = 1,28 € / kg
```

Puis il appelle le `StockService` avec des valeurs **déjà en unité de stockage** :

```
StockService::entree(matiere = Tomate, quantite = 100 /* kg */, prixReel = 1,28 /* €/kg */, …)
```

Le `StockService` ne voit jamais « cagette » ni « 32 € » : il reçoit du kg et du
€/kg, ajoute au `stock_courant` et recalcule le `pmp`. **Il ne convertit rien** —
c'est son contrat.

### Schéma mental

```
  ÉTIQUETTES                MATIÈRE                  CATALOGUE (par fournisseur)
  unites_de_mesure          unité de stockage        unité d'achat + qte_par_unite_achat
  kg, cagette, botte   ──>  Tomate = kg         <──  Dupont : cagette, ×25
                            (stock & pmp en kg)       Martin : kg, ×1
                                  ▲
                                  │  conversion UNIQUE, à la réception :
                                  │   qté_reçue × facteur  → unité de stockage
                                  │   prix_réel ÷ facteur   → prix / unité de stockage
                                  │
                            RÉCEPTION ──> StockService (ne reçoit que l'unité de stockage)
```

### Conséquences pour le code

- `unites_de_mesure` ne porte **pas** de `coefficient_conversion` (n'a jamais
  existé ; à ne jamais ajouter).
- Le `StockService` reçoit des quantités **déjà en unité de stockage** et ne
  convertit aucune unité (cf. son docblock).
- La conversion `quantite_recue × qte_par_unite_achat` et
  `prix_unitaire_reel ÷ qte_par_unite_achat` est à coder **uniquement** dans le
  futur module réception, juste avant l'appel au `StockService`.

---

## Qui a le droit d'écrire le stock

### Règle d'or

Le stock d'une matière sur un site (`stock_courant`, `pmp`, `mouvements_stock`)
n'est **jamais** écrit directement. Un **seul** service le touche, le
`StockService`, qui opère toujours sur le couple **(matière, site)** et expose
trois opérations : `entree()`, `sortie()`, `correctionInventaire()`. Tout passe
en transaction avec `lockForUpdate`, et le PMP est recalculé à chaque entrée.

> **Pourquoi ?** Le PMP et le stock courant sont des valeurs dérivées
> sensibles : si plusieurs endroits du code les modifiaient, la moindre
> divergence corromprait la valorisation sans laisser de trace. Un gardien
> unique = un seul endroit à auditer et un historique (`mouvements_stock`)
> toujours cohérent.

### Qui déclenche quoi

| Événement métier | Effet stock | Déclencheur autorisé |
|---|---|---|
| **Commande fournisseur** | **Aucun** | Une commande n'est qu'un engagement prévisionnel. Elle ne touche jamais le stock. |
| **Réception** | **Entrée** (`StockService::entree`) | **Seul** déclencheur d'entrée de stock. Atomique : entrée + dépense budgétaire dans la même transaction. |
| **Inventaire (clôture)** | **Correction** (`StockService::correctionInventaire`) | **Seul** déclencheur d'ajustement. Écart = physique − théorique, théorique figé à la clôture. |
| **Vente** *(à venir, Module Salle)* | **Sortie** (`StockService::sortie`) | Le déstockage à la vente passera par ce même gardien. |

Conséquence : on ne « corrige » jamais un stock à la main en base. Un écart
constaté = un inventaire. Une entrée de marchandise = une réception.

---

## Deux notions de coût : valorisation (PMP) vs référence (catalogue)

### Règle d'or

InResto manipule **deux coûts différents** pour une même matière, **volontairement**,
parce qu'ils répondent à deux questions distinctes. Ne jamais les confondre ni
chercher à les unifier.

| Notion | Définition | Question à laquelle elle répond | Où |
|---|---|---|---|
| **PMP** (prix moyen pondéré) | Moyenne pondérée du prix réellement payé, recalculée à chaque réception | « Combien vaut ce que j'ai **réellement en stock** ? » | `matieres_premieres.pmp` (par site) |
| **Coût de référence** | Meilleur prix catalogue : `MIN(prix_unitaire / qte_par_unite_achat)`, 0 si hors catalogue | « Combien **devrait** coûter cette recette au meilleur prix d'achat ? » | Calculé à la volée, figé dans `fiches_techniques.cout_matiere` |

### Conséquence sur les recettes

- **Coût d'une sous-recette** = `Σ quantité × PMP moyen multi-site`. C'est un
  **indicateur cuisine** (ce que coûte réellement la prod aujourd'hui), **pas**
  une valorisation comptable.
- **Coût d'une fiche technique** = basé sur le **meilleur prix catalogue**
  (référence), **jamais** le PMP. C'est un coût-cible théorique, stable, qui ne
  bouge pas quand le stock fluctue.

> **Pourquoi deux coûts ?** Le PMP suit la réalité (il monte si on a acheté cher
> récemment) ; le coût de référence sert à fixer un prix de vente et juger la
> marge théorique. Mélanger les deux ferait varier le coût d'une recette au gré
> des achats, rendant tout pilotage de marge impossible.

---

## Définitions partagées vs opérations rattachées au site

### Règle d'or

**DÉFINITIONS PARTAGÉES, OPÉRATIONS RATTACHÉES AU SITE.** Tout ce qui est
*catalogue / référentiel* est commun à toute l'entreprise ; tout ce qui est
*mouvement / valeur du jour* appartient à **un** site.

| Partagé (aucun `site_id`) | Par site (`site_id`) |
|---|---|
| `matieres_premieres`, `fournisseurs`, `catalogue_fournisseur`, `unites_de_mesure`, catégories, `sous_recettes`, `produits`, `fiches_techniques` | `stocks`, `mouvements_stock`, `budgets`, `inventaires` |

### Périmètre utilisateur

Un utilisateur travaille sur **ses** sites uniquement, via le pivot `user_site`
(avec `est_principal`). Les écrans d'**opérations** (budget, inventaire,
réception) lisent le **périmètre** de l'utilisateur (`/api/sites/perimetre`,
sans permission — ce sont ses propres sites), pas la liste de gestion.

> **Pourquoi ?** Une matière « Tomate » ou une fiche technique doit rester
> identique partout (sinon chaque site réinvente le référentiel), mais son
> **stock** et son **PMP** sont propres à chaque cuisine. La liaison historique
> utilisateur ↔ point de vente est **obsolète** : tout périmètre passe désormais
> par le **site**.

---

## Versioning des fiches techniques

### Règle d'or

Une fiche technique n'est **jamais modifiée en place**. Toute modification
**archive** la version active et **crée une nouvelle version active** (v+1),
de façon atomique. L'historique des versions est **figé**.

> **Pourquoi ?** Une fiche technique sert de référence de coût et de production
> à un instant donné. Écraser l'ancienne reviendrait à réécrire l'histoire :
> impossible de dire « à l'époque, ce plat coûtait X et se composait de Y ».
> Le versioning garantit la traçabilité des recettes et des coûts dans le temps.

Corollaire : une fiche **archivée** est immuable (suppression → 409). La cascade
de recalcul (une sous-recette change → recalcul des fiches **actives** qui
l'utilisent) ne touche que les versions actives.

---

## Prix de vente porté par la carte, pas par le produit

> **Règle à venir — Module Salle / Lot A « Produits & Carte-Produit ».**
> Consignée ici en amont du développement car c'est une décision structurante.

### Règle d'or

Un **produit** (`produits`) ne porte **aucun prix de vente**. Le prix vit sur le
pivot **`carte_produit`** (`prix_vente`, lien carte ↔ produit). Un même produit
peut donc être vendu à **des prix différents** selon la carte (donc selon le
point de vente / la saison / le canal).

> **Pourquoi ?** Le prix de vente n'est pas une propriété intrinsèque du produit
> mais une **décision commerciale contextuelle** : le même « Café » ne se vend
> pas au même prix en salle, en terrasse ou sur une carte événementielle.
> Stocker le prix sur le produit forcerait un prix unique et empêcherait toute
> politique tarifaire par carte.

### Garde-fous associés (Lot A)

- Un produit **désactivé** (`statut = inactif`) ne peut pas être ajouté à une
  carte, et n'apparaît pas à la vente.
- Un produit **référencé dans une fiche technique active** ne peut pas être
  supprimé (soft delete refusé → 409) : la recette en production en dépend.
- Sur une carte **active**, on ne **supprime** pas une ligne `carte_produit`, on
  la **désactive** (statut). Suppression réservée aux cartes `brouillon` /
  `archivee`. Idée : une carte active est un document servi en salle, son
  contenu doit rester traçable.
- Unicité **(carte, produit)** : un produit ne figure qu'une fois par carte.

---

## Une seule session de caisse ouverte par caisse

> Règle du **Module Salle / Lot B**.

### Règle d'or

À tout instant, une caisse a **au plus une** session `ouverte`. Une session est
la période entre l'ouverture (fonds initial) et la fermeture (rapprochement
caisse réelle / théorique). Tant qu'une session est ouverte sur une caisse, on
ne peut pas en ouvrir une seconde sur la **même** caisse (409).

> **Pourquoi ?** La session est l'unité de responsabilité comptable d'une caisse :
> tous les encaissements d'une période s'y rattachent pour calculer l'écart à la
> fermeture. Deux sessions ouvertes simultanément rendraient indéterminé « sur
> quelle session » un paiement tombe, cassant le contrôle d'écart.

### Comment c'est garanti

- **Index unique partiel** en base : `CREATE UNIQUE INDEX ... ON sessions_caisse
  (caisse_id) WHERE statut = 'ouverte'` (filet de sécurité, valable PostgreSQL
  et SQLite). Une fois la session fermée, la caisse peut en rouvrir une.
- **Contrôle applicatif** `lockForUpdate` dans `SessionCaisseService::ouvrir`
  pour renvoyer un **409 propre** plutôt qu'une violation d'unicité brute.

### Montant théorique & écart

- `montant_theorique = fonds_initial + Σ paiements encaissés sur la session`.
  **Point d'extension Lot C** : la table `paiements` n'existe pas encore, donc
  `SessionCaisse::montantPaye()` renvoie 0 → `montant_theorique = fonds_initial`
  pour l'instant.
- `ecart = montant_reel − montant_theorique`. `montant_reel` est **saisi
  obligatoirement** à la fermeture (0 accepté = caisse vide). **L'ouvreur n'a pas
  à être le fermeur.**
- L'accès « utiliser une session » (créer des commandes dessus, Lot C) se remonte
  via **caisse → point de vente → site**, croisé avec `user_site` et la permission
  `session_caisse.utiliser`.
