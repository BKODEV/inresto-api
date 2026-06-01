# Notes de gestion — InResto

> Document vivant. On y consigne les **règles métier** et **décisions de conception**
> qui ne se devinent pas à la lecture du code, au fur et à mesure des lots.
> Objectif : qu'un nouvel arrivant (ou nous-mêmes dans 6 mois) comprenne *pourquoi*
> le système est fait comme ça, pas seulement *comment*.

## Sommaire

- [Unités de mesure & conversion d'achat](#unités-de-mesure--conversion-dachat)

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
