# Notes de gestion — InResto

> Document vivant. On y consigne les **règles métier** et **décisions de conception**
> qui ne se devinent pas à la lecture du code, au fur et à mesure des lots.
> Objectif : qu'un nouvel arrivant (ou nous-mêmes dans 6 mois) comprenne *pourquoi*
> le système est fait comme ça, pas seulement *comment*.

## Sommaire

- [Vue d'ensemble du domaine](#vue-densemble-du-domaine)
- [Unités de mesure & conversion d'achat](#unités-de-mesure--conversion-dachat)
- [Qui a le droit d'écrire le stock](#qui-a-le-droit-décrire-le-stock)
- [Deux notions de coût : valorisation (PMP) vs référence (catalogue)](#deux-notions-de-coût--valorisation-pmp-vs-référence-catalogue)
- [Comment le coût de référence (meilleur prix catalogue) est calculé](#comment-le-coût-de-référence-meilleur-prix-catalogue-est-calculé)
- [Comment le PMP est calculé](#comment-le-pmp-est-calculé)
- [PMP vs FIFO : pourquoi on a choisi le PMP](#pmp-vs-fifo--pourquoi-on-a-choisi-le-pmp)
- [Définitions partagées vs opérations rattachées au site](#définitions-partagées-vs-opérations-rattachées-au-site)
- [Utilisateurs, rôles & permissions](#utilisateurs-rôles--permissions)
- [Versioning des fiches techniques](#versioning-des-fiches-techniques)
- [La méthodologie fait partie de la fiche](#la-méthodologie-fait-partie-de-la-fiche)
- [Prix de vente porté par la carte, pas par le produit](#prix-de-vente-porté-par-la-carte-pas-par-le-produit)
- [Une seule session de caisse ouverte par caisse](#une-seule-session-de-caisse-ouverte-par-caisse)
- [Le service de cuisine, indépendant de la caisse](#le-service-de-cuisine-indépendant-de-la-caisse)
- [Déstockage à la vente : au service, jamais bloquant](#déstockage-à-la-vente--au-service-jamais-bloquant)
- [Encaissement, remises encadrées & annulations](#encaissement-remises-encadrées--annulations)
- [Un mode de paiement est un contenant](#un-mode-de-paiement-est-un-contenant)
- [Fermer une session qui porte encore des notes](#fermer-une-session-qui-porte-encore-des-notes)
- [Le pourboire n'est pas du chiffre d'affaires](#le-pourboire-nest-pas-du-chiffre-daffaires)
- [Versements : l'argent qui sort du tiroir](#versements--largent-qui-sort-du-tiroir)
- [La chaîne entre deux services](#la-chaîne-entre-deux-services)
- [Trois documents pour le client, deux gabarits](#trois-documents-pour-le-client-deux-gabarits)
- [Le service se fait par tours](#le-service-se-fait-par-tours)
- [Ce qu'un document imprimé fige](#ce-quun-document-imprimé-fige)
- [Journal d'activité : on relit, on ne réécrit pas](#journal-dactivité--on-relit-on-ne-réécrit-pas)
- [Machines à états — mémo transversal](#machines-à-états--mémo-transversal)
- [Pilotage / Reporting : lecture seule, adossé au « vendu »](#pilotage--reporting--lecture-seule-adossé-au--vendu-)

---

## Vue d'ensemble du domaine

> À lire en premier : le fil qui relie tous les objets. Les sections suivantes
> détaillent chaque règle ; celle-ci donne la carte.

InResto pilote une chaîne **de l'achat à l'encaissement**, sur plusieurs sites.
Tout tourne autour d'**une matière première valorisée par site** (son stock, son
coût) et d'**une recette** qui la transforme en **produit vendu**.

### Le flux, de bout en bout

```
 RÉFÉRENTIELS PARTAGÉS (aucun site)          OPÉRATIONS (rattachées à UN site)
 ───────────────────────────────────         ──────────────────────────────────
 Fournisseur                                  
   └─ Catalogue (prix + qte_par_unite_achat)  
        │  on ne commande qu'au catalogue     
        ▼                                      
 Commande fournisseur ─────envoi────►  Réception ──►  Stock du site (+ PMP)
   (engagement, 0 stock)                 (SEULE entrée)    + Dépense (budget du site)
                                                              ▲
 Matière première (unité de stockage)  ───────────────────────┘
   │
   ├─►  Sous-recette   (coût = Σ qté × PMP moyen multi-site)
   │        │
   └─►  Fiche technique VERSIONNÉE
            (coût = matières au meilleur catalogue + sous-recettes au PMP)
                 │  décrit la recette d'UN…
                 ▼
 Produit (sans prix) ──inscrit sur──►  Carte (porte le prix de vente)
                                          │  rattachée à un Point de vente / Caisse
                                          ▼
 Table de salle ─► Session de caisse (ouverte) ─► Commande client ─► lignes
                                                        │
                              passage « servie » ──────►│──►  Sortie de stock du site
                              (déstocke via la fiche FIGÉE)     (via StockService)
                                                        ▼
                              Paiements / Remises / Annulations ─► Encaissement
                                                        │
                                                        ▼
                              Inventaire (corrige le stock)   Reporting (lecture seule)
```

### Les grands objets en une ligne

| Objet | Rôle métier | Partagé / par site |
|---|---|---|
| **Fournisseur / Catalogue** | Qui vend quoi, à quel prix, sous quel conditionnement | Partagé |
| **Matière première** | L'ingrédient, avec **une** unité de stockage | Partagé (déf.) / stock par site |
| **Commande fournisseur** | Engagement d'achat au catalogue — **ne touche pas** le stock | — |
| **Réception** | **Seule** entrée de stock ; convertit l'achat, recalcule le PMP, crée la dépense | Site |
| **Sous-recette** | Préparation intermédiaire (fond de sauce…), indicateur cuisine au **PMP** | Partagé |
| **Fiche technique** | Recette d'un produit, **versionnée**, coût de **référence** (catalogue) | Partagé |
| **Produit / Carte** | Le produit n'a **pas** de prix ; le prix vit sur la **carte** | Partagé |
| **Session de caisse / Commande client** | La vente en salle, du couvert à l'encaissement | Site (via PDV) |
| **Inventaire** | Le **seul** juge de paix des écarts de stock | Site |
| **Budget / Dépense** | L'enveloppe et sa consommation | Site |
| **Reporting** | Synthèses **lecture seule** (ne modifie jamais rien) | Site + périmètre |

### Les 5 invariants à retenir

1. **Le stock ne bouge qu'à 3 endroits** (réception, vente servie, inventaire), tous via le `StockService` — [[Qui a le droit d'écrire le stock]].
2. **Deux coûts distincts, jamais confondus** : le **PMP** (ce que vaut le stock réel) et le **coût de référence catalogue** (ce que devrait coûter une recette).
3. **Définitions partagées, opérations par site** : une matière/fiche est commune, son stock et son budget sont propres à chaque cuisine.
4. **Une unité = une étiquette** : aucune conversion dimensionnelle, sauf le facteur d'achat appliqué **à la réception**.
5. **Traçabilité par figement** : la fiche utilisée à la vente et la version de recette sont **figées** pour que l'histoire (coût, composition) reste vraie.

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
  pas de double conversion interne. **Garanti côté serveur** : une ligne de
  **fiche technique** comme de **sous-recette** ne choisit **pas** son unité —
  le service (`FicheTechniqueService` / `SousRecetteService`) y estampe toujours
  l'unité de stockage de la matière (l'`unite_id` éventuellement envoyé par le
  client est ignoré). Sans conversion dimensionnelle, une unité libre sur la
  ligne (ex. « litre » pour une matière en kg) fausserait silencieusement le
  coût *et* le déstockage ; le front l'affiche donc en lecture seule. À ne pas
  confondre avec `sous_recettes.unite_id`, l'unité **produite** par la
  sous-recette (celle-là reste libre : c'est une vraie étiquette sans matière à
  laquelle s'aligner).
- `catalogue_fournisseur.prix_unitaire` est exprimé **par unité d'achat**
  (ex. prix par cagette), alors que `matieres_premieres.pmp` est **par unité de
  stockage** (ex. par kg). Le passage de l'un à l'autre = la division par
  `qte_par_unite_achat`, à la réception.
- `qte_par_unite_achat` (`decimal 10,3`, défaut `1`, requis et `> 0` à l'API) =
  « combien d'unités de stockage dans 1 unité d'achat de CE fournisseur ». Le
  défaut `1` couvre le cas identité (on achète et on stocke dans la même unité).
- **L'unité d'une ligne d'achat = l'unité d'achat du catalogue, jamais libre.**
  Une ligne de **commande fournisseur** comme de **réception** ne choisit pas son
  unité : le serveur y estampe l'`unite_achat_id` du catalogue pour le couple
  (fournisseur, matière) — celle-là même qui porte `qte_par_unite_achat`, donc la
  conversion du PMP. Laisser choisir une autre unité divergerait du facteur et
  **fausserait silencieusement le PMP** (`quantite × facteur` et
  `prix ÷ facteur`). Le front l'affiche en lecture seule.

### Exemple déroulé : la Tomate, stockée en kg

**Étape 1 — Unités.** La cheffe crée les étiquettes : `kg`, `cagette`. Aucun lien
entre elles dans le système.

**Étape 2 — Matière.** Elle crée *Tomate* avec **unité de stockage = kg**.
Désormais `stock_courant = 100` signifie **100 kg**, et `pmp = 800` signifie
**800 FCFA / kg**.

**Étape 3 — Catalogue fournisseur.** Le conditionnement varie d'un fournisseur à
l'autre, donc chaque ligne porte son propre facteur :

| Fournisseur | `unite_achat_id` | `prix_unitaire` (par unité d'achat) | `qte_par_unite_achat` |
|---|---|---|---|
| Primeur Dupont | cagette | 19 000 FCFA / cagette | 25 (1 cagette = 25 kg) |
| Grossiste Martin | kg | 850 FCFA / kg | 1 (cas identité) |

**Étape 4 — Réception (la SEULE conversion).** La cheffe reçoit **4 cagettes** de
Primeur Dupont, prix réel facturé **20 000 FCFA/cagette**. Le module réception applique :

```
Quantité en stockage = quantite_recue × qte_par_unite_achat
                     = 4 × 25 = 100 kg

Prix par unité de stockage = prix_unitaire_reel ÷ qte_par_unite_achat
                          = 20 000 ÷ 25 = 800 FCFA / kg
```

Puis il appelle le `StockService` avec des valeurs **déjà en unité de stockage** :

```
StockService::entree(matiere = Tomate, quantite = 100 /* kg */, prixReel = 800 /* FCFA/kg */, …)
```

Le `StockService` ne voit jamais « cagette » ni « 20 000 FCFA » : il reçoit du kg
et du FCFA/kg, ajoute au `stock_courant` et recalcule le `pmp`. **Il ne convertit
rien** — c'est son contrat.

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
| **Vente (au service)** | **Sortie** (`StockService::sortie`) | Déclenché par `CommandeClientService` au passage au service, via la fiche technique figée. Jamais bloquant : un stock insuffisant part en négatif plutôt que d'empêcher de servir. |

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
- **Coût d'une fiche technique** = **matières directes** valorisées au **meilleur
  prix catalogue** (référence, stable) **+** portion **sous-recettes** héritée de
  leur `cout_matiere`, lui-même **basé sur le PMP**. Donc : une fiche **sans**
  sous-recette est un coût-cible **pur catalogue** (jamais le PMP) ; dès qu'elle
  **contient** une sous-recette, cette part-là est valorisée au **PMP** — la fiche
  mélange alors les deux. Détail du calcul ci-dessous.

> **Pourquoi deux coûts ?** Le PMP suit la réalité (il monte si on a acheté cher
> récemment) ; le coût de référence sert à fixer un prix de vente et juger la
> marge théorique. Mélanger les deux ferait varier le coût d'une recette au gré
> des achats, rendant tout pilotage de marge impossible.

---

## Comment le coût de référence (meilleur prix catalogue) est calculé

### La formule (en clair)

Le coût de référence d'une fiche technique (`fiches_techniques.cout_matiere`) est
la **somme de deux parts**, recalculée à la volée (`FicheTechniqueService::recalculerCoutMatiere`) :

**Part 1 — matières directes.** Pour chaque matière de la fiche :

```
coût ligne = quantité (en unité de stockage) × meilleur prix catalogue de la matière

meilleur prix catalogue = MIN, sur TOUS les fournisseurs qui cataloguent la matière, de :
                          prix_unitaire ÷ qte_par_unite_achat
```

- `prix_unitaire` est exprimé **par unité d'achat** (ex. par cagette). La division
  par `qte_par_unite_achat` le ramène **par unité de stockage** (ex. par kg) — le
  même pivot qu'à la réception (cf. « Unités de mesure & conversion d'achat »).
- On prend le **MIN** parce que c'est le *meilleur* prix : ce que coûterait la
  matière achetée au fournisseur le moins cher.
- Matière **hors catalogue** (aucun fournisseur) → coût **0**. Pas d'erreur, mais
  la fiche est alors **sous-évaluée** : c'est un signal de paramétrage à combler.

**Part 2 — portion sous-recettes.** Pour chaque sous-recette de la fiche :

```
coût = (sous_recette.cout_matiere ÷ quantite_produite) × quantité utilisée
```

⚠️ `sous_recette.cout_matiere` est **basé sur le PMP** (cf. « Comment le PMP est
calculé » et le coût des sous-recettes), **pas** sur le catalogue. Cette part
n'est donc **pas** au meilleur prix catalogue — voir l'encadré plus bas.

Le total est **arrondi à 2 décimales** et figé dans `fiches_techniques.cout_matiere`.

### Exemple déroulé

Fiche « Poulet braisé » :

| Ligne | Détail | Coût |
|---|---|---|
| Poulet (matière directe) | 0,25 kg × **meilleur prix catalogue 2 500 FCFA/kg** | 625 FCFA |
| Fond de sauce (sous-recette) | la sous-recette produit **2 L** pour un `cout_matiere` de **3 000 FCFA** (au PMP) → 3 000 ÷ 2 = **1 500 FCFA/L**, puis × 0,30 L | 450 FCFA |

```
cout_matiere = 625  +  450  =  1 075 FCFA
               (catalogue)  (PMP, via la sous-recette)
```

La matière directe (poulet) est au prix catalogue ; le fond de sauce entre au PMP :
**la même fiche mélange les deux référentiels**.

### Pourquoi la portion sous-recette échappe au catalogue

Une sous-recette (« fond de sauce ») **ne s'achète pas** à un fournisseur : elle
n'a donc **aucun prix catalogue propre**. Elle a été conçue comme un **indicateur
cuisine** valorisé au PMP (« combien coûte réellement cette prod aujourd'hui »).
La fiche technique **réutilise** cette valeur déjà calculée plutôt que de
ré-exploser la sous-recette en matières et de les re-tarifer au meilleur prix
catalogue.

> **Conséquence assumée** : une fiche **sans** sous-recette est un coût de
> référence **pur catalogue** (stable, insensible au stock) ; une fiche **avec**
> sous-recette **mélange** catalogue (matières directes) et PMP (part
> sous-recette), qui lui, bouge avec les achats. À garder en tête pour le
> pilotage de marge. Rendre le coût *purement* catalogue supposerait d'exploser
> les sous-recettes en matières et de les tarifer au catalogue — non fait à ce jour.

---

## Comment le PMP est calculé

### La formule (en clair)

À chaque réception, le système recalcule le PMP de la matière sur le site :

```
Nouveau PMP = (Quantité en stock × PMP actuel  +  Quantité reçue × Prix réel d'achat)
              ────────────────────────────────────────────────────────────────────────
                               Quantité en stock  +  Quantité reçue
```

**Cas particulier** : si le stock était à zéro (ou négatif), on ne peut pas
« pondérer » avec un stock vide — le nouveau PMP devient simplement le prix
d'achat de la réception.

### Exemple déroulé

La cuisine a **100 kg de tomates** valorisés à **800 FCFA/kg** (son PMP actuel).
Elle reçoit **50 kg supplémentaires** à **950 FCFA/kg** (prix réel facturé).

```
Nouveau PMP = (100 × 800)  +  (50 × 950)
              ───────────────────────────
                    100  +  50

            =  80 000 + 47 500   =  127 500   =  850 FCFA/kg
               ─────────────────     ───────
                     150               150
```

Le PMP passe de 800 à 850 FCFA/kg : l'achat plus cher a tiré le coût moyen vers
le haut, mais il est **lissé** sur l'ensemble du stock — pas seulement sur les
50 kg reçus.

### Ce que le PMP ne fait PAS

- Une **sortie** (vente, perte, correction d'inventaire) **ne modifie jamais le
  PMP**. Consommer de la tomate ne change pas son coût moyen unitaire ; seule
  une entrée (réception) peut le faire bouger.
- L'inventaire corrige la quantité en stock, pas le PMP.

---

## PMP vs FIFO : pourquoi on a choisi le PMP

Il existe deux grandes méthodes de valorisation du stock. InResto a évalué les
deux avant de choisir le PMP. Voici la comparaison honnête.

### Principe de chaque méthode

**PMP (Prix Moyen Pondéré)** — méthode choisie.
> Chaque nouvelle réception "dilue" le prix existant dans un prix moyen unique.
> On ne distingue pas les lots : 1 matière = 1 seul coût à l'instant T.

**FIFO (Premier Entré, Premier Sorti)** — méthode non retenue.
> On suppose que les premières unités reçues sont les premières consommées. Le
> stock est une file de lots, chacun avec son propre prix d'achat. Quand on
> consomme, on « grignote » le lot le plus ancien d'abord.

### Tableau comparatif

| Critère | PMP | FIFO |
|---|---|---|
| **Nombre de valeurs à gérer** | Un seul PMP par matière par site | Autant de lignes que de lots non épuisés |
| **Impact d'un achat cher** | Lissé sur tout le stock existant | Isolé dans son lot ; les autres lots gardent leur prix |
| **Suivi nécessaire** | Aucun suivi de lot | Date + prix de chaque réception, consommées dans l'ordre |
| **Complexité technique** | Faible : une ligne `stocks` par (matière, site) | Élevée : table de lots, requête FIFO à chaque sortie |
| **Cohérence avec le terrain** | Bonne pour les périssables (on ne trie pas ses tomates par date d'arrivée) | Meilleure pour les produits non périssables à longue durée (bouteilles millésimées, conserves vieillissantes) |
| **Pilotage quotidien** | Simple : « ma tomate coûte 850 FCFA/kg aujourd'hui » | Plus précis mais plus lourd à lire |
| **Dérive si stock négatif** | Le PMP reste stable (aucun recalcul sur sortie) | La file de lots peut se désynchroniser du stock réel |

### Pourquoi le PMP dans notre cas

1. **Produits périssables en priorité.** Un restaurant travaille surtout avec
   des produits frais dont la durée de vie est courte. La notion de « premier
   lot entré » n'a pas de sens réel en cuisine : on utilise ce qu'on a, pas
   nécessairement dans l'ordre d'arrivée.

2. **Pas de gestion de lots.** Le FIFO exigerait de lier chaque sortie à un lot
   précis — cela implique une table de lots, des numéros de livraison, et une
   logique de file à chaque vente. C'est de la complexité sans valeur ajoutée
   pour une cuisine.

3. **Un seul chiffre lisible pour la cheffe.** Le PMP donne une réponse directe
   à « combien me coûte ma matière aujourd'hui ? ». Ce chiffre change
   uniquement à la réception — il est donc stable entre deux livraisons, ce qui
   facilite le suivi de marge au quotidien.

4. **Inventaire = seul juge de paix.** InResto gère les écarts via l'inventaire
   (cf. « Qui a le droit d'écrire le stock »), pas en traçant chaque gramme
   consommé lot par lot. Le PMP s'y prête parfaitement : on sait combien vaut
   ce qu'il reste, pas d'où il vient.

> **En résumé :** le FIFO aurait été pertinent pour un distributeur de vins ou
> un entrepôt de conserves avec une rotation lente. Pour une cuisine multi-site
> gérant des produits frais, le PMP est plus simple à maintenir, plus lisible
> pour les gérants, et tout aussi fiable sur les décisions du jour.

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

### Le périmètre est une frontière, pas un confort d'affichage

Le serveur **applique** le périmètre, il ne se contente pas de le proposer :

| Où | Comment |
|---|---|
| Lectures (stock, mouvements, inventaires, réceptions, budgets, PDF) | `App\Support\SitePerimetre` : un `site_id` hors périmètre renvoie **403**, son absence borne la requête aux sites de l'utilisateur |
| Écritures (réception, inventaire, budget) | règle `App\Rules\SiteDansPerimetre` sur le champ `site_id` → **422** |
| Accès direct par identifiant | policies `Inventaire` / `Reception` / `Budget` : filtrer l'index ne protège pas `GET /api/inventaires/{id}` |

**Deux notions de « global » cohabitent, à ne pas confondre** : en
**opérationnel**, seul le **super-admin** dépasse son périmètre ;
**`reporting.global`** n'élargit que la **lecture analytique**. Consulter les
chiffres d'un site ne vaut pas autorisation d'y écrire.

> **Pourquoi cette sévérité ?** Stock, PMP et budgets sont propres au site, et il
> n'existe **aucun mouvement de transfert inter-sites**. Une réception ou un
> inventaire posé sur le mauvais site ne se répare que par des corrections
> d'inventaire des deux côtés, avec l'impact sur le PMP que cela suppose.

---

## Utilisateurs, rôles & permissions

### Règle d'or

Un utilisateur reçoit des **rôles** ; un rôle porte des **permissions** au format
`domaine.action` (ex. `reception.create`). **Les permissions sont définies dans le
code** (`PermissionCatalog`), l'administrateur ne les **crée jamais** — il les
**assigne** aux rôles. Deux garde-fous encadrent toute gestion d'utilisateur : la
**hiérarchie anti-escalade** et le **périmètre de sites**.

> **Pourquoi figer les permissions dans le code ?** Elles reflètent les capacités
> réelles de l'application (des routes protégées). Laisser un admin en inventer
> créerait des permissions sans effet, ou des trous de sécurité. Le code est la
> source de vérité ; l'admin compose des rôles à partir de cette liste.

### Hiérarchie anti-escalade

Chaque rôle système porte un **niveau** ; on ne peut ni gérer un utilisateur, ni
assigner un rôle, de niveau **supérieur ou égal** au sien.

| Rôle | Niveau |
|---|---|
| `super-admin` | 100 |
| `gerant` | 50 |
| `chef-cuisine` / `econome` | 30 |
| `caissier` | 20 |
| `serveur` | 10 |

- **Gérer** un utilisateur (éditer, désactiver, reset mdp) exige que sa cible soit
  de niveau **strictement inférieur** au sien (`niveauMax()` = le plus haut de ses
  rôles).
- **Assigner** un rôle exige que ce rôle soit de niveau strictement inférieur au
  sien : un gérant ne peut pas créer un autre gérant ni un super-admin.

> **Pourquoi ?** Sans cette règle, un compte compromis ou un gérant zélé pourrait
> s'octroyer (ou octroyer) des droits au-dessus de lui — une **escalade de
> privilèges**. Le niveau matérialise « je ne crée que plus petit que moi ».

### Périmètre de sites

Un non-super-admin ne **rattache** et ne **gère** des utilisateurs que dans **ses
propres sites** (pivot `user_site`). Rattacher à un site hors périmètre est refusé.

**Au moins un site est obligatoire à la création d'un compte**, et on ne peut pas
vider le périmètre d'un compte existant (omettre le champ le laisse intact,
l'envoyer vide est refusé).

> **Pourquoi ?** Le périmètre borne désormais toutes les lectures et écritures
> rattachées à un site — voir [Définitions partagées vs opérations rattachées au
> site](#définitions-partagées-vs-opérations-rattachées-au-site). Un compte sans
> site n'aurait accès à rien : il serait en 403 permanent sur le stock, les
> inventaires, les réceptions et les budgets.

### On ne dépose personne sur un écran qu'il ne peut pas ouvrir

La racine `/`, la sortie de connexion et la route inconnue ne visent pas un
écran en dur : elles résolvent la **première entrée de menu que l'utilisateur
peut réellement ouvrir**, dans l'ordre du menu — qui est déjà l'ordre
d'importance métier.

> **Pourquoi ?** Elles pointaient toutes `/dashboard`, écran gouverné par
> `reporting.dashboard`. Un caissier ou un cuisinier, qui ne l'ont pas,
> atterrissaient à chaque connexion sur une page qui ne pouvait que refuser de
> se charger.

Corollaire : une entrée de menu doit porter la **même permission que l'API**
derrière elle. Un refus doit se voir avant le clic, et se lire comme un refus —
pas comme une panne de chargement.

### Le super-admin court-circuite tout

Le `super-admin` **ignore** hiérarchie **et** périmètre (il gère tout, sur tous les
sites — pas de rattachement `user_site`). En contrepartie, un garde-fou empêche de
**supprimer ou désactiver le dernier super-admin actif** (anti lock-out : il doit
toujours rester au moins un accès total au système).

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

## La méthodologie fait partie de la fiche

### Règle d'or

Une fiche technique porte **comment on produit le plat**, pas seulement ce qu'il
coûte : **étapes** ordonnées (avec une durée facultative), **matériel** et
**points critiques**. Les trois sont **versionnés avec la fiche**, comme le coût.

> **Pourquoi ?** Une fiche qui liste les matières sans dire quoi en faire n'est
> pas exécutable : un extra tient la liste des courses, pas la recette. C'est le
> jour où on embauche pour un service qu'on découvre ce qui manquait.

### L'ordre vient de la position, jamais du client

Le tableau reçu **est** l'ordre : le serveur numérote d'après la position, il n'y
a aucun champ `ordre` au contrat. Deux étapes au même rang rendraient la fiche
imprimée non déterministe — et un intérimaire ferait mariner après avoir servi.
Réordonner, côté écran, est un déplacement de ligne.

### Les points critiques ne sont pas des étapes

Une étape se lit une fois, en séquence. Un **point critique** — cuisson à cœur,
allergène, chaîne du froid — doit rester visible pendant **toute** la production.
D'où une table à part, un enum fermé (`TypePointCritique`), et une position **en
tête** du bloc méthodologie sur le PDF.

### Ce qui reste facultatif, et pourquoi

| Élément | Facultatif | Raison |
|---|---|---|
| Toute la méthodologie | oui | Les fiches existantes n'en ont pas, et un plat d'assemblage n'a pas de mode opératoire. L'exiger casserait la saisie. |
| `duree_minutes` d'une étape | oui | « Laisser reposer » n'a pas toujours une durée qu'on veuille chiffrer ; un `0` imposé se lirait comme « instantané ». |

`duree_totale_minutes` ne somme que les étapes **chiffrées**, et vaut `null`
quand aucune ne l'est — annoncer « 0 min » sur une recette non chiffrée serait un
mensonge utile à personne.

---

## Prix de vente porté par la carte, pas par le produit

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

- Le théorique ne compte que **le tiroir**, et l'écart se lit **contenant par
  contenant** → voir « Un mode de paiement est un contenant », qui porte le
  détail du calcul.
- `ecart = montant_reel − montant_theorique`, figé à la fermeture avec son
  signataire (`ferme_par`) — **l'ouvreur n'a pas à être le fermeur**.
- L'accès « utiliser une session » (créer des commandes dessus, Lot C) se remonte
  via **caisse → point de vente → site**, croisé avec `user_site` et la permission
  `session_caisse.utiliser`.

---

## Le service de cuisine, indépendant de la caisse

### Règle d'or

La brigade a **son propre service** (`sessions_cuisine`), rattaché au **point de
vente** et **jamais ouvert ni fermé par la caisse**. Une ligne s'y rattache au
moment où elle **part en cuisine** (`envoyee_le`).

> **Pourquoi ce qu'il apporte ?** Les ventes disent quel **serveur** a vendu un
> plat. Elles ne disent jamais quelle **équipe** l'a produit. C'est la seule
> question à laquelle ce module répond.

### Pourquoi indépendant, et pas ouvert par la caisse

Trois raisons, dans l'ordre où elles mordent :

1. Un point de vente porte **plusieurs caisses pour une seule cuisine** : coupler
   obligerait à désigner arbitrairement laquelle commande le service.
2. La brigade travaille **avant** la première ouverture de caisse (mise en place)
   et **après** la dernière fermeture (nettoyage). Ces heures disparaîtraient du
   suivi.
3. Une caisse fermée par erreur **fermerait la cuisine en plein service**.

Même garde-fou que la caisse en revanche : **un seul service ouvert par point de
vente**, index unique partiel en base, pour la même raison — deux services
ouverts rendraient le rattachement des lignes ambigu.

### La brigade est un pivot, pas une colonne

`ouvert_par` dit qui a tourné la clé ; `session_cuisine_user` dit **qui a tenu le
service**. Le chef ouvre, trois commis produisent. Qui ouvre est ajouté d'office à
l'équipe, et l'équipe se **corrige à la fermeture** — c'est là qu'on sait qui a
réellement travaillé, remplacements compris.

### Deux règles qui suivent le principe « rien ne bloque un service »

- **Aucun service ouvert ⇒ le tour part quand même**, simplement sans être imputé
  à une équipe. Un oubli de la brigade n'a pas à immobiliser la salle.
- **La fermeture est refusée (409) tant que des plats envoyés ne sont pas
  annoncés au pass.** Les laisser rattachés à un service clos les ferait
  disparaître de la file, sans que personne ne les termine.

### Production

`GET /sessions-cuisine/{id}/production` agrège **par produit** : quantité et CA,
adossés aux **lignes** comme tout le reporting. Les lignes annulées en sont
exclues — la brigade les a préparées, mais elles n'ont rien vendu.

### La brigade se recrute parmi les postes de production

Un poste de production porte les **deux moitiés** de l'organisation du pass : les
**catégories** disent ce qu'on y produit, les **membres** qui le tient. Être
rattaché à au moins un poste d'un point de vente, c'est **être de la cuisine** de
ce point de vente — et c'est ce que propose le sélecteur de brigade.

> **Pourquoi ?** Il déroulait tout le personnel du site, serveurs et caissiers
> compris. Et surtout il passait par `GET /users`, qui exige `utilisateur.view` —
> une permission d'**administration du personnel** que le chef de cuisine n'a
> pas. Il était donc en **403 sur le seul écran qu'il tient**, sans voir personne
> à inscrire.

Le personnel affectable est servi par une lecture **minimale** — `{id, nom}` —
sur `GET /points-de-vente/{id}/personnel`, gouvernée par `poste_cuisine.view` :
cocher des noms ne demande pas d'ouvrir les dossiers du personnel.

**Un extra reste inscriptible** : `?cuisine=1` restreint aux membres des postes,
sans le drapeau on obtient tout le personnel du site. Borner la brigade à
l'organigramme rendrait un service tenu par un intérimaire imputable à personne.

Un membre doit appartenir au **site** du point de vente — refus explicite en 422,
là où un `sync` aurait accepté sans broncher quelqu'un d'un autre établissement.

### Pas d'entité « Équipe », et pourquoi

Un service stocke des **individus** (`session_cuisine_user`), jamais une
référence à une équipe nommée. Une équipe change de composition : si le service
ne portait qu'un `equipe_id`, modifier « Équipe Matin » en mars réécrirait qui a
travaillé en **janvier** — exactement ce qu'interdit la règle « les valeurs
d'audit sont figées, jamais recalculées ».

Une équipe nommée ne pourrait donc être qu'un **pré-remplissage** du sélecteur.
Elle s'ajoutera sans rien casser le jour où les services se stabiliseront ;
tant qu'ils tournent avec des extras, elle serait périmée en permanence.

### Les postes ne sont pas un paramètre global

Ils vivent sous **CUISINE**, pas sous Paramètres : c'est l'organigramme du chef.
Tant qu'ils étaient un onglet de Paramètres, il ne pouvait littéralement pas les
ouvrir — `parametre.view` n'est accordée qu'au gérant.

> **La règle générale** : une entrée d'écran et la permission de l'**API qui
> l'alimente** doivent être tenues par les mêmes rôles. Sinon l'écran s'affiche
> et se charge vide, ce qui se lit comme une panne et non comme un refus.

---

## Déstockage à la vente : au service, jamais bloquant

> Règle du **Module Salle / Lot C-1**.

### Règle d'or

Le stock d'une vente n'est déchargé qu'au **passage `servie`** d'une commande
client (le KDS marque le plat servi), **jamais** à la prise de commande ni à
l'envoi en cuisine. C'est cohérent avec le gardien unique du stock
(cf. « Qui a le droit d'écrire le stock ») : la vente est une **sortie**
(`StockService::sortie`, type `sortie_vente`).

> **Pourquoi à « servie » et pas avant ?** Tant que le plat n'est pas produit, la
> matière n'est pas réellement consommée. Déstocker à la commande surévaluerait
> la consommation en cas d'annulation avant production.

### « Prêt » et « servi » sont deux gestes, un seul déstocke

Une ligne porte désormais trois horodatages : `envoyee_le` (partie en cuisine),
`prete_le` (annoncée au pass) et `servie_le` (emportée en salle). `servie_le`
portait auparavant les deux derniers faits à la fois, ce qui rendait le temps de
production inmesurable : le chrono du KDS comptait aussi l'attente sous la lampe,
qui n'est pas de la responsabilité de la cuisine. Séparés, les deux durées se
lisent — `envoyee_le → prete_le` est le temps du chef, `prete_le → servie_le`
celui de la salle.

**Le déstockage reste sur `servie_le`, et lui seul.** La raison n'est pas
comptable mais opérationnelle :

> **« Prêt » est un signal de cuisine, donc RÉVERSIBLE** — on dévalide un plat
> annoncé trop tôt (`annulerPret()`). **« Servi » est irréversible.** Accrocher
> les mouvements de stock à l'annonce les ferait battre au rythme des
> corrections du pass. Le stock ne s'accroche qu'à l'irréversible.

Corollaire : `AnnulationService` continue de tester `estServie()` pour arbitrer
entre `remise_en_stock` et `perte`. Déstocker au prêt l'aurait forcé à basculer
sur `estPrete()`, et un plat annoncé puis annulé serait devenu une perte alors
que rien n'a quitté la cuisine.

**L'annonce est facultative.** Servir une ligne jamais annoncée reste permis et
horodate les deux au même instant : une maison qui n'utilise pas le pass garde
exactement le comportement d'avant, sans laisser `prete_le` vide sur toute sa
production.

**Pas de statut de note « prête ».** La préparation est partiellement prête par
nature (deux lignes annoncées, trois en cuisson) ; un statut au niveau de la note
ne sait pas l'exprimer. Le cycle de vie reste sur la ligne, et
`StatutCommandeClient` ne bouge pas.

### La granularité est la LIGNE, jamais le ticket

Annoncer comme servir acceptent une liste de lignes, et les écrans s'en servent :
on touche un plat pour l'annoncer ou l'emporter seul, le bouton du bas prend
l'ensemble. Ce n'est pas un confort.

En cuisine, une équipe tient les entrées et une autre les plats, alors qu'un même
ticket porte les deux : n'annoncer que le ticket entier ferait attendre les
entrées jusqu'à ce que les plats sortent.

Au pass, c'est une question d'exactitude du stock. Servir déstocke : le runner
qui n'emporte que les entrées d'une note dont les plats attendent encore sous la
lampe déchargerait des plats jamais sortis de cuisine, et la dérive
n'apparaîtrait qu'à l'inventaire.

Les deux files portent la **catégorie du produit** sur chaque ligne. Le système
ne sait pas ce qu'est une « entrée » — les catégories sont saisies par
l'établissement, il ne peut donc pas trier à la place de la cuisine. Il lui donne
de quoi lire : une puce pour repérer ses plats dans un ticket mêlé.

### Postes de production : la brigade n'est pas la carte

`categories_produit` est une taxonomie **commerciale** — sa colonne `ordre`
range les sections d'une carte pour le client. La brigade est une taxonomie de
**production** : qui cuit quoi. Les confondre casse sur deux points : une carte
porte quinze catégories quand un pass en compte trois, et « Cocktails » et
« Softs » sont deux sections de carte pour un seul bar.

D'où `postes_cuisine`, rattaché au **point de vente** (un rooftop et un
restaurant n'ont pas le même pass), et un **pivot** `categorie_poste_cuisine`
plutôt qu'une clé étrangère sur la catégorie : celle-ci est une définition
partagée, sans `site_id`, et ne peut pas porter une décision d'exploitation
locale. Le pivot rend d'ailleurs service — la même catégorie « Cocktails » peut
relever du bar au restaurant et du chaud au rooftop.

Le système **n'apprend jamais ce qu'est une entrée** : les catégories sont
saisies par l'établissement, il ne peut donc pas trier à sa place. Il lui fait
déclarer ses postes une fois, et y accrocher ses propres catégories.

#### La règle de sûreté

> Un plat dont la catégorie n'est rattachée à **aucun** poste — ou un produit
> sans catégorie — reste visible de **TOUS** les écrans.

Une configuration incomplète doit dégrader vers « tout le monde le voit », jamais
vers « personne ne le voit ». Un plat invisible, c'est une table qu'on ne sert
jamais, et l'erreur ne se découvre qu'au moment où le client réclame. Même
raison pour un poste inconnu ou appartenant à un autre pass : on ne filtre pas,
plutôt que de renvoyer une file vide qui se lirait « service terminé ».

Corollaire : un établissement qui ne déclare **aucun** poste retrouve exactement
l'écran d'avant, sans rien paramétrer. Le filtre ne coûte rien à qui n'en veut
pas.

### Deux files, deux métiers

- **KDS** (`GET /api/kds/{pdv}`) — ce qui reste à produire : envoyé, pas encore
  annoncé. Groupé **par tour**, la cuisine produit ticket par ticket.
- **Pass** (`GET /api/pass/{pdv}`) — ce qui attend d'être emporté : annoncé, pas
  encore servi. Groupé **par note**, l'unité de travail du runner étant un voyage
  vers une table : deux tours prêts pour la table 12 font un seul déplacement.

Annoncer est gouverné par `commande_client.marquer_pret`, et non par
`commande_client.update` comme le service. C'est tout l'intérêt de la séparation :
le rôle `chef-cuisine` portait `update` faute de mieux — ce qui lui ouvrait au
passage la modification et le service des notes de salle — et ne porte plus que
la lecture et l'annonce. **La cuisine annonce, la salle emporte.**

### Ce qui est déchargé

Pour chaque ligne de commande, on lit la **fiche technique active** du produit
et on décharge :

- ses **matières directes** : `quantité_fiche × quantité_ligne_commande` ;
- ses **sous-recettes**, **explosées en matières premières au prorata** :
  `qté_matière_dans_sous-recette / quantité_produite × quantité_dans_fiche × quantité_ligne`.

La **version de fiche utilisée est figée** sur la ligne (`fiche_technique_id`) :
on garde la trace de *ce qui a réellement été déstocké*, même si la fiche est
re-versionnée ensuite (cf. « Versioning des fiches techniques »).

### Deux principes non négociables

1. **Une vente n'est JAMAIS bloquée.** Le stock peut devenir négatif (un service
   ne s'arrête pas parce que le logiciel croit le stock à zéro). Le `StockService`
   ne lève pas d'erreur de stock insuffisant pour une vente.
2. **Un produit sans fiche active ne bloque pas non plus.** On journalise
   (`Log::warning('DESTOCKAGE_MANQUANT', …)`) et on passe à la ligne suivante :
   la commande est tout de même servie. C'est un signal à corriger côté recettes,
   pas un point d'arrêt en plein service.

> **Conséquence** : le stock théorique peut diverger du réel (ventes sans fiche,
> stock négatif). C'est **assumé** : l'inventaire reste le seul juge de paix
> (cf. « Qui a le droit d'écrire le stock »), et les `Log::warning` tracent les
> trous de paramétrage à combler.

---

## Encaissement, remises encadrées & annulations

> Règles du **Module Salle / Lot C-2**.

### Les montants d'une commande sont calculés, jamais stockés

`montant_du`, `montant_paye`, `reste_a_payer` ne sont **pas** des colonnes : ils
sont recalculés à la volée à chaque lecture, à partir des lignes actives, des
remises et des paiements.

```
montant_du     = Σ(lignes actives : quantité × prix_unitaire) − Σ(remises)   (plancher 0)
montant_paye   = Σ(paiements.montant)          ← le pourboire n'y entre pas
reste_a_payer  = montant_du − montant_paye
```

> **Pourquoi ?** Une valeur stockée se désynchronise dès qu'une ligne, une remise
> ou un paiement bouge. Le seul chiffre figé est `prix_unitaire` sur la ligne
> (le prix au moment de la vente — cf. « Prix de vente porté par la carte »).

### Une commande encaissée est immuable

Après encaissement (`servie → encaissee`), **plus aucune** ligne, remise ou
paiement ne peut être ajouté (→ 409). L'encaissement exige `reste_a_payer = 0`.
Monnaie et pourboire ne sont plus « gérés à la main » : ils ont chacun leur
colonne sur le paiement (cf. « Le pourboire n'est pas du chiffre d'affaires »).
La table passe alors **« à débarrasser »**.

### Les remises sont encadrées par un seuil

Au-delà d'un **seuil configurable** (`config('inresto.remise_seuil_manager')`,
défaut **15 %**), une remise exige la permission `remise.autoriser_au_dela_seuil`
(sinon 403). Le pourcentage *effectif* est comparé au seuil : une remise en
montant fixe est rapportée au total des lignes.

> **Pourquoi ?** Une petite remise commerciale est un geste de serveur ; une
> grosse remise est une décision de manager. Le seuil matérialise cette frontière
> sans coder en dur un rôle.

### Annuler une ligne déjà servie : `remise_en_stock` ou `perte`

Tant que la commande n'est pas servie, annuler une ligne ne touche pas au stock
(rien n'a été déchargé). **Une fois servie**, la ligne a déjà été déstockée
(cf. « Déstockage à la vente ») : l'annulation impose alors un `sort`.

| `sort` | Sens métier | Effet stock |
|---|---|---|
| `remise_en_stock` | La matière n'a pas été consommée, elle retourne en stock | `StockService::entree` au PMP courant, sur la **fiche figée** de la ligne |
| `perte` | Le plat a été préparé puis jeté | **Aucun mouvement** : le `sortie_vente` initial reste, on ne fait que tracer |

> **Pourquoi figer la fiche ?** Le re-crédit doit porter sur *ce qui a réellement
> été déchargé* — la version de fiche utilisée à la vente, pas la version
> courante (cf. « Versioning des fiches techniques »). Sans fiche figée, on
> journalise et on ne recrédite rien plutôt que de deviner.

Toutes les annulations (ligne ou commande entière) sont **journalisées** dans la
table polymorphe `annulations` (avec motif obligatoire). Annuler une **commande
entière** n'est possible qu'avant le service (ouverte / envoyée en cuisine) et
libère la table.

### Relire les remises et les annulations

`GET /api/remises` et `GET /api/annulations`, en **lecture seule**, paginées,
sous `remise.view` et `annulation.view`. Filtres propres à chaque nature :
`type` pour une remise, `sort` et `annulable_type` pour une annulation.

> **Pourquoi en plus du journal ?** Le journal répond à « que s'est-il passé ce
> soir » : il plafonne chaque source et mêle les natures. Ces listes répondent à
> la question de contrôle, sur une période — combien a-t-on remis et sur quels
> motifs ; ce qui a été annulé après service est-il reparti en stock ou passé en
> perte.

Le périmètre de sites suit l'objet annulé, et il y a **deux chemins** : une
commande porte sa table, une **ligne** ne la porte que par sa commande. La
requête traverse donc la relation polymorphe des deux façons.

---

## Un mode de paiement est un contenant

> Règles du **Module Salle / Lot D** — la fermeture de caisse.

### Règle d'or

Un mode de paiement ne dit pas seulement *comment* le client a payé : il dit **où
l'argent s'accumule**. Les espèces vont dans le tiroir, la carte dans le
terminal, le chèque dans la pochette, le virement à la banque. Rapprocher une
caisse, c'est comparer **contenant par contenant** ce que le système attend à ce
que quelqu'un y constate.

`modes_de_paiement.compte_en_caisse` marque les modes dont l'argent finit
**physiquement dans le tiroir** compté le soir.

> **Pourquoi cette colonne ?** `montant_theorique` valait `fonds + Σ TOUS les
> paiements`, alors que `montant_reel` est ce qu'on **compte**. Chaque virement
> creusait donc un manquant qui n'avait jamais existé, et le système ne pouvait
> pas s'en sortir seul : la table ne portait que `libelle` et `statut`, rien ne
> disait la nature d'un mode.

```
montant_theorique = fonds_initial
                  + Σ (montant + pourboire) des paiements EN TIROIR
                  − Σ versements faits pendant le service
```

`ordre` sert deux choses : l'ordre des boutons au comptoir, et le départage
quand plusieurs modes sont en tiroir (le premier est présélectionné).

### Une ligne par mode ACTIF, pas par mode utilisé

`rapprochements_caisse` reçoit une ligne pour **chaque mode actif**, même si
personne ne l'a utilisé du service. Sans cela, une carte encaissée sur le
terminal mais jamais saisie dans le système n'aurait aucune ligne où
apparaître — précisément l'erreur qu'on cherche.

Le théorique de chaque contenant est **figé** à la fermeture, comme l'écart de
la session : un rapprochement est une pièce d'audit, il ne se recalcule pas
après coup.

### On ne souffle jamais le montant attendu

Aucun écran ne pré-remplit ni n'affiche le théorique **avant** la saisie du
compte — ni pour le tiroir, ni contenant par contenant, ni pour le fonds
initial à l'ouverture.

> **Pourquoi ?** Un montant affiché se recopie. Le rapprochement ne mesure alors
> plus que la capacité du caissier à lire un écran. Compter à l'aveugle est ce
> qui donne sa valeur à la comparaison.

### Tolérance, et sens de l'écart

`parametre_etablissement.tolerance_ecart_caisse` (par site, comme la devise) :
en dessous de ce montant, l'écart est réputé normal — avec de la petite monnaie
on ne tombe jamais pile. `0` désactive la règle. Même convention que
`matieres_premieres.seuil_alerte_stock`.

`SessionCaisse::sensEcart()` qualifie plutôt que d'afficher un nombre nu :
`juste` · `tolere` · `excedent` · `manquant`. Un **excédent** n'est pas une
bonne nouvelle : il signale aussi bien un rendu de monnaie oublié qu'une vente
non enregistrée.

### Tout contenant doit être compté

Un restaurant classique n'accepte ni virement ni chèque : chaque mode actif
correspond donc à un endroit vérifiable le soir même. La fermeture **refuse**
(409) tant qu'un contenant reste sans compte. Zéro est un compte valide et
attendu — c'est l'absence de saisie qui est refusée.

> Un mode qu'on ne saurait pas contrôler n'a pas à rester actif : il se
> **désactive** dans Paramètres → Modes de paiement, et disparaît alors de la
> fermeture. C'est l'échappatoire prévue, pas la fermeture forcée.

### La fermeture forcée

Sous la permission dédiée **`session_caisse.forcer_fermeture`** (gérant, pas
caissier), et avec un **motif obligatoire** figé dans
`sessions_caisse.motif_forcage`. Le motif s'affiche en tête du rapport Z, pas en
note de bas de page.

Distincte de `session_caisse.update` à dessein : savoir fermer une caisse ne
doit pas suffire à contourner le contrôle.

> **Le forçage passe outre le compte, jamais l'impayé** (cf. section suivante).
> Ce sont deux problèmes différents, une seule porte ne doit pas ouvrir les deux.

---

## Fermer une session qui porte encore des notes

### Deux cas, deux traitements

| État de la note | Sens métier | Traitement à la fermeture |
|---|---|---|
| `servie` **non réglée** | Le client a consommé et n'a pas payé — c'est un impayé | **Bloque (409).** Se solde ou s'annule, ne se contourne pas |
| `ouverte` / `envoyee_en_cuisine` | La table mange encore à l'heure de la relève | **Reportée** sur la session suivante |

> **Pourquoi bloquer l'impayé ?** Fermer par-dessus rendrait la note orpheline :
> son argent ne compterait dans le théorique d'aucune session, et l'impayé
> disparaîtrait sans laisser de trace.

### Le report a lieu à l'OUVERTURE

`SessionCaisseService::ouvrir()` repointe les notes encore en cours de la
session précédente vers la nouvelle — pas à la fermeture, où la session
d'accueil n'existe pas encore.

> **Pourquoi c'est sans risque** : un paiement n'est accepté que sur une note
> **servie**. Une note reportée n'en porte donc aucun, et la repointer ne
> déplace pas d'argent d'une session à l'autre.

---

## Le pourboire n'est pas du chiffre d'affaires

### Trois montants sur un paiement, trois questions

| Colonne | Répond à |
|---|---|
| `montant` | Ce qui **solde la note** — la vente, donc le chiffre d'affaires |
| `pourboire` | Ce qui reste en tiroir **sans appartenir à l'établissement** |
| `montant_recu` | Le **billet tendu**, dont on déduit la monnaie à rendre |

Note de 7 500, le client pose 10 000 et laisse 1 000 : `montant` 7 500,
`pourboire` 1 000, `montant_recu` 10 000, **monnaie rendue 1 500**.

`montant_recu` est **obligatoire dès que le mode alimente le tiroir**
(`compte_en_caisse`), appoint juste compris. Sans lui, la monnaie rendue ne se
déduit pas : le reçu retombait sur le montant encaissé et affirmait un appoint
que personne n'avait constaté. Hors tiroir (carte, virement), aucun billet n'est
tendu — le champ reste vide plutôt que d'inventer un chiffre.

### Deux exigences contradictoires en apparence

1. **Le pourboire DOIT entrer dans le théorique du tiroir.** Il y est
   physiquement. L'en exclure le ferait ressortir en **excédent de caisse** —
   c'est-à-dire comme le signal d'une vente non enregistrée.
2. **Il ne doit JAMAIS compter comme recette.** Le CA se lit sur les lignes de
   commande (`Σ quantité × prix_unitaire`), où un pourboire n'a rien à faire.

Le CA est donc **structurellement immunisé** : un pourboire n'étant pas une
ligne, aucun rapport de pilotage ne peut le compter. Seule la vue **trésorerie**
(`VentesReportService::modesPaiement()`) lit les paiements, et elle somme
`montant` seul, en exposant les pourboires à part.

`reste_a_payer` l'ignore aussi : `montantPaye()` somme `montant`, donc une note
reste soldée par sa seule vente.

### Le bénéficiaire ne demande aucune colonne

C'est **`commande.user_id`**, le serveur qui tient la note. Le pourboire est
ainsi rattaché au serveur, à la note et à la session à la fois, ce qui couvre
les trois lectures sans rien dupliquer.

Le solde dû à chacun se calcule : *encaissé sur ses tables* − *déjà reversé*.
Le serveur peut suivre le sien sous **`pourboire.suivre_les_siens`**, une
permission **attachée à aucun rôle par défaut** — c'est à la direction de
décider si un serveur voit ses propres pourboires, et de l'attribuer ou de la
retirer quand elle veut. L'API correspondante n'accepte **aucun identifiant en
paramètre** : on ne lit que soi-même.

> **Hors périmètre, assumé** : les pourboires remis de la main à la main ne
> passent pas par la caisse et n'existent donc pas dans le système. Les écrans
> le disent explicitement, pour qu'on ne croie pas la liste exhaustive.

---

## Versements : l'argent qui sort du tiroir

### Règle d'or

Un **versement** trace une sortie d'espèces vers le coffre, la banque, la
direction, un tiers — ou vers un serveur, pour lui reverser ses pourboires.
Table en **ajout seul** : un mouvement d'argent constaté ne se réécrit pas.

> **Pourquoi ?** Le théorique valait `fonds + encaissé`, sans aucun terme de
> sortie. Porter une partie de la recette au coffre en plein service creusait un
> manquant fantôme exactement égal à la somme sortie.

### Deux champs pour deux questions différentes

| Champ | Question |
|---|---|
| `beneficiaire_id` (FK users, nullable) | **De quel solde** cet argent se retire-t-il ? |
| `remis_a` (texte libre) | **Qui a physiquement emporté** l'argent ? |

Reverser un pourboire éteint la dette d'un serveur précis : un nom écrit à la
main ne le permet pas (« Kouassi », « K. Aké » et « kouassi ake » ne se
recoupent pas). Les autres destinations n'ont **aucun solde** à diminuer — un
coffre ne doit rien à personne — et se contentent de `remis_a`, qui accueille
très bien une société de collecte ou un convoyeur.

La règle « obligatoire pour `personnel`, interdit ailleurs » vit dans le
FormRequest : un lien posé par erreur sur un dépôt bancaire effacerait
silencieusement la dette d'un serveur.

### Avant ou après la fermeture : deux comptabilisations

```
pendant le service  → se retranche du théorique de CETTE session
après la fermeture  → explique l'écart d'ouverture de la SUIVANTE
```

Le booléen **`apres_fermeture`** enregistre lequel des deux, au lieu de le
redéduire en comparant `date_heure` à `ferme_le`. Ces colonnes sont à la
seconde : un caissier qui verse dans la foulée de sa clôture tombait entre les
deux cas — ni retranché d'un théorique déjà figé, ni compté dans la transition.
Le service, lui, sait sans ambiguïté si la session était ouverte.

### Les sorties se lisent en face des sessions

L'écran **Sessions de caisse** liste les cent dernières sorties de tiroir sous
la table des sessions, avec leur moment (`en_service` / après fermeture), leur
destination et le nom de qui a emporté l'argent.

> **Pourquoi ?** C'est la moitié manquante de la lecture d'un écart. Un manquant
> de 200 000 s'explique en une ligne quand le versement au coffre est affiché à
> côté, et se cherche une heure quand il ne l'est pas.

La liste et le geste partagent la même permission `versement.create` : voir
sortir l'argent et le faire sortir relèvent du même niveau de confiance.

---

## La chaîne entre deux services

### Règle d'or

Chaque session bouclait déjà correctement, mais la **chaîne** restait ouverte :
on fermait à 183 500, on rouvrait le lendemain à 10 000, et rien ne disait
combien était sorti ni pour aller où. Les deux sessions se rapprochaient
parfaitement même si 3 500 disparaissaient en route.

```
ecart_ouverture = fonds_initial − ( compté à la fermeture précédente
                                    − versements faits depuis )
```

**Zéro** quand la transition est conforme : passage de main à main entre
caissiers, ou versement qui explique exactement la différence. **Null** quand il
n'y a rien à comparer — première session de la caisse, ou précédente fermée sans
que le tiroir ait été compté.

Figé à l'ouverture, comme les autres montants de rapprochement : un versement
enregistré plus tard ne doit pas réécrire l'histoire d'une session déjà ouverte.

> **On n'impose rien.** Le fonds initial n'est ni contraint ni contrôlé à la
> saisie, et une divergence n'empêche pas d'ouvrir : elle se **signale**, à
> charge pour un superviseur de la lire. Un service ne s'arrête pas parce que le
> tiroir ne tombe pas juste — même principe que « une vente n'est jamais
> bloquée ».

---

## Trois documents pour le client, deux gabarits

| Document | Quand | Ce qu'il dit |
|---|---|---|
| **Addition** | Avant paiement, sur demande du client | Ce qu'il **doit**. Pas de remerciement, pas de monnaie |
| **Reçu** | Après paiement | Ce qu'il a **payé**, ce qu'il a tendu, la monnaie rendue, dont pourboire |
| **Facture** | Sur demande | Document A4, mention non fiscale |

Addition et reçu sont **un seul gabarit à deux états** (ticket 80mm) : c'est le
même papier, la même imprimante, le même geste au comptoir. Le modèle
d'encaissement est **« tout au comptoir »** — le client se déplace pour payer,
il n'y a pas de terminal itinérant en salle.

> Le reçu **mentirait** sans traitement du pourboire : `monnaie = tendu − dû`
> annoncerait au client une monnaie qu'il ne recevra pas. Le calcul retranche le
> pourboire et l'affiche sur sa propre ligne.

### Le rapport Z (fermeture)

Même format 80mm, imprimé au poste et classé dans le tiroir. Il porte : les
bornes de session et son signataire, les notes encaissées et le CA, le
**rapprochement contenant par contenant**, les **versements**, la **transition à
l'ouverture**, les **pourboires par serveur** (la liste qu'on lit pour les
reverser), et les gestes sensibles du service. En tête, s'il y a lieu, le
**motif de forçage**.

Le journal d'impression (`impressions`) est **polymorphe** : il trace aussi bien
un document de commande qu'un rapport de session. Sans quoi le ticket Z serait
le seul document d'argent sans trace.

---

## Le service se fait par tours

### Règle d'or

Le cycle de vie appartient à la **ligne**, pas à la note. `envoyee_le` horodate
le départ en cuisine et sert d'**identifiant de tour** — les lignes parties
ensemble partagent la même valeur, ce qui permet à la cuisine de distinguer un
ticket neuf de ce qui est déjà en production.

> **Pourquoi ?** Une note ne servait qu'un seul tour : tout ajout était refusé
> dès l'envoi en cuisine, donc le dessert commandé après les plats n'avait nulle
> part où aller. Or un repas se commande en plusieurs fois.

### `servie_le` est ce qui rend le multi-tour sûr

`servir()` déstockait **toutes** les lignes actives de la note. Servir un second
tour aurait redéstocké le premier, et la dérive ne serait apparue qu'à
l'inventaire. Une ligne déjà horodatée n'est plus déstockée.

Le KDS interroge donc les **lignes**, plus les commandes.

---

## Ce qu'un document imprimé fige

### Règle d'or

Un document imprimé doit rester **identique à lui-même** quel que soit l'état
ultérieur du plan de salle ou du personnel.

- **`commandes_client.table_numero`** : le numéro au moment de la commande. Les
  gabarits lisaient `$commande->table->numero`, donc la valeur du jour :
  renommer une table réécrivait toutes ses factures passées. Une note de mars
  réimprimée en septembre ne correspondait plus au ticket remis au client.
- **`commandes_client.encaisse_par`** et **`sessions_caisse.ferme_par`** : le
  geste d'argent porte son signataire. `user_id` ne désigne que celui qui a
  ouvert — l'ouvreur n'a pas à être le fermeur.

### Archiver une table, ne pas la supprimer

La suppression dure reste réservée aux tables qui n'ont **jamais servi** : dès
qu'une commande existe, la table est indéracinable. `archivee_le` retire une
table du service de façon réversible — sans quoi une salle réaménagée traîne à
vie ses anciennes tables dans le plan, dans le sélecteur de la caisse et sur la
planche QR, dont les autocollants continuent de servir un menu.

> **Colonne dédiée plutôt que `SoftDeletes`** : le scope global exclurait la
> table de `CommandeClient::table()`, et archiver casserait la réimpression de
> tout son historique.
> **Plutôt que `statut`** : ce dernier est piloté automatiquement par le flux de
> commande (libre / occupée / à débarrasser), il écraserait l'archivage au
> premier effet de bord.

---

## Journal d'activité : on relit, on ne réécrit pas

`JournalService` **n'écrit rien**. Les traces existent déjà, chacune dans la
table du module qui la produit, avec son contexte métier propre : `annulations`
porte un motif et le sort du stock, `remises` un motif et une valeur,
`paiements` un mode, `versements` une destination, `impressions` un type.

> **Pourquoi pas une table d'événements générique ?** Elle perdrait ce contexte
> et créerait **deux vérités** à maintenir — celle du module et sa copie.

**Contrepartie assumée** : l'assemblage se fait en PHP, pas en SQL. La fenêtre
de lecture doit donc rester bornée — le contrôleur impose une plage de dates
(par défaut la journée) et un plafond par source.

Lecture seule, sous **`journal.view`** : rien à créer ni à modifier, donc pas de
`crud()` dans le catalogue de permissions.

**Six sources, toutes filtrables** : `annulation`, `remise`, `paiement`,
`encaissement`, `versement`, `impression`. Filtres complémentaires : plage de
dates, site, et **point de vente** — un site porte plusieurs comptoirs, et une
anomalie se cherche sur celui où elle a eu lieu.

L'API renvoie avec les événements la **fenêtre réellement lue** (`periode`) et le
**volume retenu** (`total`), et l'écran les affiche.

> **Pourquoi ?** Sans dates fournies, la fenêtre est ramenée à la journée
> courante, et chaque source est plafonnée. Taire ces deux bornes laisserait
> prendre la table pour l'historique complet de la période.

---

## Machines à états — mémo transversal

> Récapitulatif des cycles de vie. Le détail (garde-fous, permissions) est dans
> les sections dédiées ; ici on voit d'un coup d'œil **où va chaque document** et
> **quand le stock bouge**.

| Document | Cycle de vie | Écriture verrouillée à | Stock ? |
|---|---|---|---|
| **Commande fournisseur** | `brouillon → envoyee → partiellement_recue → cloturee` (`annulee` sauf depuis `cloturee`) | modifiable **uniquement** en `brouillon` | jamais |
| **Réception** | confirmée en une fois ; pilote le passage `partiellement_recue` / `cloturee` de la commande | — | **entrée** (+ dépense) |
| **Commande client** | `ouverte → envoyee_en_cuisine → servie → encaissee` (`annulee` avant service) — le cycle est porté par la **ligne**, une note se sert en plusieurs tours | immuable après `encaissee` | **sortie** au passage `servie` de **chaque ligne** |
| **Session de caisse** | `ouverte → fermee` | une seule `ouverte` par caisse ; fermeture refusée tant qu'un contenant n'est pas compté ou qu'une note servie reste impayée | — |
| **Carte** | `brouillon → active → archivee` | une seule `active` par PDV ; sur une carte `active` on **désactive** une ligne, on ne la supprime pas | — |
| **Fiche technique** | `active → archivee` (versioning : jamais modifiée en place, v+1) | une archive est immuable | — |
| **Inventaire** | `en_cours → cloture` | figé à la clôture | **correction** à la clôture |
| **Table de salle** | `libre / occupee / reservee / a_debarrasser`, plus un retrait du service réversible (`archivee_le`, hors statut) | — | — |

Les **types de mouvement de stock** (`mouvements_stock.type`) tracent l'origine de
chaque écriture : `entree_reception`, `sortie_vente`, `correction_inventaire`, et
les sorties « fuites » `perte` / `casse` / `offert` / `repas_personnel`. Tout
passe par le `StockService` (cf. « Qui a le droit d'écrire le stock »).

---

## Pilotage / Reporting : lecture seule, adossé au « vendu »

### Règle d'or

Le module Reporting **ne modifie jamais** aucune donnée : il **agrège** ce que les
autres modules ont produit. Toute la lecture des ventes part d'une base commune :
une commande client compte comme **vendue** dès le statut `servie` (et le reste une
fois `encaissee`) — **l'encaissement ne change pas la base**. Date de référence =
`commandes_client.date_heure`.

> **Pourquoi « servie » et pas « encaissée » ?** La vente est réelle dès que le
> plat est produit et servi (c'est aussi là que le stock est déchargé). Attendre
> l'encaissement décalerait le CA et le food cost d'une étape purement financière.

### Food cost : théorique vs réel

C'est le cœur du pilotage de marge. Pour chaque ligne vendue **dotée d'une fiche
figée** :

| Mesure | Calcul | Sens |
|---|---|---|
| **Coût théorique** | `fiche.cout_matiere × quantité vendue` | ce que la recette **devrait** coûter (coût de référence figé) |
| **Coût réel** | `Σ (quantité × pmp_au_moment)` des mouvements `sortie_vente` de la ligne | ce que le stock a **réellement** coûté à la sortie |
| **Écart (fuite)** | réel − théorique | sur-consommation, pertes, portions non maîtrisées |

- **CA valorisé** = uniquement les lignes avec **fiche figée**. Les lignes vendues
  **sans** fiche (produit non fiché au moment de la vente) sont **isolées** dans un
  bloc `lignes_non_valorisees`, **exclues des ratios**, et leur part de CA est
  signalée (`part_ca_non_valorise_pct`) — un signal de paramétrage à combler, pas
  un chiffre faussé en silence.

### Périmètre du reporting

- **`reporting.global`** (ou super-admin) → **tous les sites** (aucun filtre).
- Sinon → agrégations **restreintes aux sites de l'utilisateur** ; demander un
  `site_id` hors de son périmètre renvoie **403**.
- Permissions **par famille** : `reporting.ventes`, `reporting.food_cost`,
  `reporting.stock`, `reporting.budget`, `reporting.dashboard`, plus
  `reporting.global` (accès multi-site transversal).

Rapports disponibles : **ventes**, **food cost**, **stock**, **budget**, et un
**tableau de bord** de synthèse transversale.

### Deux lectures qui se demandent explicitement

| Rapport | Paramètre | Ce qu'il ouvre |
|---|---|---|
| Ventes → produits | `ordre=asc\|desc`, croisé avec `tri=ca\|quantite` | le **flop** autant que le top : ce qu'on retire de la carte, pas seulement ce qui la porte |
| Stock → écarts d'inventaire | `detail=true` | le **ligne à ligne** : quelles matières expliquent l'écart |

> Le flop se lit **parmi ce qui s'est vendu** : la requête part des lignes
> vendues, un produit à zéro vente n'a aucune ligne et n'y figure pas. L'écran le
> dit, sans quoi on lit la liste comme celle des invendus.

Le détail des écarts n'est **pas** demandé par défaut : sur une période large,
c'est une ligne par matière comptée et par inventaire, pour une question qu'on
ne pose qu'une fois l'écart repéré.
