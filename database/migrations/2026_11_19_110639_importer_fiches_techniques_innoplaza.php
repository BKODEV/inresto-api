<?php

declare(strict_types=1);

use App\Models\FicheTechnique;
use App\Models\SousRecette;
use App\Services\FicheTechniqueService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Import des fiches techniques INNOPLAZA Marcory (prix d'achat octobre 2026).
 *
 * Les fiches papier sont rédigées pour un lot (10 portions, 12, 4…) ; la fiche
 * en base décrit UNE portion vendue, car c'est ce que la vente déstocke. Toutes
 * les quantités ci-dessous sont donc déjà divisées par le rendement et
 * exprimées dans l'unité de stockage de la matière (kg, L ou pièce) : grammes
 * et millilitres sont convertis, une botte ou une gousse compte pour une pièce.
 *
 * Laissés de côté : le sel « QS », l'eau, et les éléments « au choix » du
 * petit-déjeuner (viennoiserie, jus, boisson chaude), qui n'ont pas de quantité
 * déterminée. Les épices en quantité infime sont arrondies au minimum de la
 * colonne (0,001).
 *
 * Les planches sont des formules à choix : produits `est_menu` sans fiche,
 * leur composition n'étant pas encore modélisée.
 *
 * Les prix des fiches ne sont pas importés : ils vivent dans le catalogue
 * fournisseur. Les coûts sont recalculés depuis ce qui y figure déjà.
 *
 * Idempotente : matières, sous-recettes et produits sont repris par libellé,
 * et un produit qui a déjà une fiche active n'est pas touché.
 */
return new class extends Migration
{
    private const MATIERES = [
        'Beurre doux' => ['Crèmerie', 'kg'],
        'Beurre doux (micro-portion)' => ['Crèmerie', 'pce'],
        'Cheddar' => ['Crèmerie', 'kg'],
        'Crème fraîche entière' => ['Crèmerie', 'kg'],
        'Crème liquide entière' => ['Crèmerie', 'L'],
        'Crème épaisse' => ['Crèmerie', 'kg'],
        'Fromage à raclette' => ['Crèmerie', 'kg'],
        'Glace au lait' => ['Crèmerie', 'kg'],
        'Glace vanille' => ['Crèmerie', 'kg'],
        'Lait' => ['Crèmerie', 'L'],
        'Parmesan' => ['Crèmerie', 'kg'],
        'Yaourt nature' => ['Crèmerie', 'kg'],
        'Œufs' => ['Crèmerie', 'pce'],
        'Ail (gousse)' => ['Fruits & Légumes', 'pce'],
        'Aubergine' => ['Fruits & Légumes', 'kg'],
        'Avocat' => ['Fruits & Légumes', 'kg'],
        'Banane plantain' => ['Fruits & Légumes', 'kg'],
        'Carotte' => ['Fruits & Légumes', 'kg'],
        'Champignons de Paris surgelés' => ['Fruits & Légumes', 'kg'],
        'Ciboulette' => ['Fruits & Légumes', 'kg'],
        'Citron jaune' => ['Fruits & Légumes', 'kg'],
        'Citron vert' => ['Fruits & Légumes', 'kg'],
        'Concombre' => ['Fruits & Légumes', 'kg'],
        'Coriandre (botte)' => ['Fruits & Légumes', 'pce'],
        'Courgette' => ['Fruits & Légumes', 'kg'],
        'Cébette (botte)' => ['Fruits & Légumes', 'pce'],
        'Céleri' => ['Fruits & Légumes', 'kg'],
        'Gingembre frais' => ['Fruits & Légumes', 'kg'],
        'Jeunes pousses' => ['Fruits & Légumes', 'kg'],
        'Mesclun' => ['Fruits & Légumes', 'kg'],
        'Navet' => ['Fruits & Légumes', 'kg'],
        'Oignon' => ['Fruits & Légumes', 'kg'],
        'Oignon rouge' => ['Fruits & Légumes', 'kg'],
        'Persil (botte)' => ['Fruits & Légumes', 'pce'],
        'Piment frais' => ['Fruits & Légumes', 'kg'],
        'Poireau' => ['Fruits & Légumes', 'kg'],
        'Poivron' => ['Fruits & Légumes', 'kg'],
        'Pomme' => ['Fruits & Légumes', 'kg'],
        'Pomme de terre' => ['Fruits & Légumes', 'kg'],
        'Pousses de germes' => ['Fruits & Légumes', 'kg'],
        'Salade de fruits de saison' => ['Fruits & Légumes', 'kg'],
        'Sucrine' => ['Fruits & Légumes', 'pce'],
        'Tomate' => ['Fruits & Légumes', 'kg'],
        'Tomate cerise' => ['Fruits & Légumes', 'kg'],
        'Échalote' => ['Fruits & Légumes', 'kg'],
        'Épinards (botte)' => ['Fruits & Légumes', 'pce'],
        'Anchois marinés' => ['Produits de la mer', 'kg'],
        'Calamar' => ['Produits de la mer', 'kg'],
        'Crabe précuit' => ['Produits de la mer', 'kg'],
        'Crevettes' => ['Produits de la mer', 'kg'],
        'Escargots (chair précuite)' => ['Produits de la mer', 'kg'],
        'Filet de mostelle' => ['Produits de la mer', 'kg'],
        'Gambas' => ['Produits de la mer', 'kg'],
        'Langouste' => ['Produits de la mer', 'kg'],
        'Noix de Saint-Jacques' => ['Produits de la mer', 'kg'],
        'Saumon frais' => ['Produits de la mer', 'kg'],
        'Saumon fumé' => ['Produits de la mer', 'kg'],
        'Sole' => ['Produits de la mer', 'kg'],
        'Thon rouge' => ['Produits de la mer', 'kg'],
        'Tilapia' => ['Produits de la mer', 'kg'],
        'Œufs de poisson noirs' => ['Produits de la mer', 'kg'],
        'Blanc de poulet' => ['Viandes & Volailles', 'kg'],
        'Bœuf haché' => ['Viandes & Volailles', 'kg'],
        'Côte de porc' => ['Viandes & Volailles', 'kg'],
        'Filet de bœuf' => ['Viandes & Volailles', 'kg'],
        'Jambon de bœuf' => ['Viandes & Volailles', 'kg'],
        'Magret de canard' => ['Viandes & Volailles', 'kg'],
        'Poitrine de bœuf' => ['Viandes & Volailles', 'kg'],
        'Poitrine d’agneau' => ['Viandes & Volailles', 'kg'],
        'Poulet africain entier' => ['Viandes & Volailles', 'pce'],
        'Poulet entier' => ['Viandes & Volailles', 'pce'],
        'Ribs de porc' => ['Viandes & Volailles', 'kg'],
        'Épaule de mouton' => ['Viandes & Volailles', 'kg'],
        'Ail en poudre' => ['Épicerie', 'kg'],
        'Ail en purée' => ['Épicerie', 'kg'],
        'Arôme Maggi' => ['Épicerie', 'L'],
        'Attiéké (semoule de manioc)' => ['Épicerie', 'kg'],
        'Baguette' => ['Épicerie', 'pce'],
        'Boule de pain' => ['Épicerie', 'pce'],
        'Chapelure panko' => ['Épicerie', 'kg'],
        'Chocolat blanc de couverture' => ['Épicerie', 'kg'],
        'Chocolat noir de couverture' => ['Épicerie', 'kg'],
        'Concentré de tomate' => ['Épicerie', 'kg'],
        'Confiture maison' => ['Épicerie', 'kg'],
        'Cornichons' => ['Épicerie', 'kg'],
        'Cube d\'assaisonnement' => ['Épicerie', 'pce'],
        'Cumin' => ['Épicerie', 'kg'],
        'Curcuma' => ['Épicerie', 'kg'],
        'Curry en poudre' => ['Épicerie', 'kg'],
        'Câpres' => ['Épicerie', 'kg'],
        'Farine de blé' => ['Épicerie', 'kg'],
        'Fleur de sel' => ['Épicerie', 'kg'],
        'Gingembre en poudre' => ['Épicerie', 'kg'],
        'Gousse de vanille' => ['Épicerie', 'pce'],
        'Granola maison (portion)' => ['Épicerie', 'pce'],
        'Huile d’olive' => ['Épicerie', 'L'],
        'Huile végétale' => ['Épicerie', 'L'],
        'Jus de pomme' => ['Épicerie', 'L'],
        'Ketchup' => ['Épicerie', 'L'],
        'Laurier (feuille)' => ['Épicerie', 'pce'],
        'Linguine à l’encre de seiche' => ['Épicerie', 'kg'],
        'Marinade feu de bois' => ['Épicerie', 'kg'],
        'Mayonnaise' => ['Épicerie', 'kg'],
        'Maïzena' => ['Épicerie', 'kg'],
        'Miel' => ['Épicerie', 'L'],
        'Mini pancakes (portion de 3)' => ['Épicerie', 'pce'],
        'Moutarde' => ['Épicerie', 'kg'],
        'Noix de cajou' => ['Épicerie', 'kg'],
        'Nouilles aux œufs' => ['Épicerie', 'kg'],
        'Oignon en poudre' => ['Épicerie', 'kg'],
        'Origan séché' => ['Épicerie', 'kg'],
        'Pain burger brioché' => ['Épicerie', 'pce'],
        'Paprika' => ['Épicerie', 'kg'],
        'Paprika fumé' => ['Épicerie', 'kg'],
        'Persil séché' => ['Épicerie', 'kg'],
        'Piment en poudre' => ['Épicerie', 'kg'],
        'Poivre de Penja' => ['Épicerie', 'kg'],
        'Poivre noir' => ['Épicerie', 'kg'],
        'Poivre vert en saumure' => ['Épicerie', 'kg'],
        'Porto rouge' => ['Épicerie', 'L'],
        'Poudre de kankankan' => ['Épicerie', 'kg'],
        'Pâte feuilletée' => ['Épicerie', 'kg'],
        'Riz noir' => ['Épicerie', 'kg'],
        'Riz rond' => ['Épicerie', 'kg'],
        'Riz thaï' => ['Épicerie', 'kg'],
        'Sauce huître' => ['Épicerie', 'L'],
        'Sauce soja' => ['Épicerie', 'L'],
        'Sel' => ['Épicerie', 'kg'],
        'Sucre' => ['Épicerie', 'kg'],
        'Sucre roux' => ['Épicerie', 'kg'],
        'Sésame blanc' => ['Épicerie', 'kg'],
        'Sésame noir' => ['Épicerie', 'kg'],
        'Thym séché' => ['Épicerie', 'kg'],
        'Vin blanc sec' => ['Épicerie', 'L'],
        'Vinaigre' => ['Épicerie', 'L'],
    ];

    private const SOUS_RECETTES = [
        'Sauce cocktail' => [
            'quantite_produite' => 0.3,
            'lignes' => [
                'Œufs' => 1,
                'Moutarde' => 0.01,
                'Huile végétale' => 0.2,
                'Ketchup' => 0.06,
                'Citron vert' => 0.025,
                'Piment frais' => 0.002,
                'Paprika' => 0.001,
            ],
        ],
        'Sauce aïoli' => [
            'quantite_produite' => 0.3,
            'lignes' => [
                'Ail (gousse)' => 6,
                'Œufs' => 1,
                'Moutarde' => 0.01,
                'Huile végétale' => 0.2,
                'Huile d’olive' => 0.05,
                'Citron vert' => 0.05,
            ],
        ],
        'Sauce crème Signature' => [
            'quantite_produite' => 0.12,
            'lignes' => [
                'Crème fraîche entière' => 0.08,
                'Ciboulette' => 0.02,
                'Ail en purée' => 0.02,
            ],
        ],
    ];

    private const PLANCHES = [
        'La Braise' => 'Planche au feu de bois, 2 personnes',
        'Le Braisé' => 'Planche au feu de bois, 4 personnes',
        'L’Étincelle' => 'Planche au feu de bois, 1 personne',
        'Le Feu de Brousse' => 'Planche au feu de bois, 6 personnes',
        'Le Grand Feu' => 'Planche au feu de bois, 8 personnes',
    ];

    private const FICHES = [
        [
            'produit' => 'Escargots africains en persillade',
            'categorie' => 'Entrées',
            'description' => 'Façon escargots de Bourgogne, beurre persillé gratiné',
            'lignes' => [
                'Escargots (chair précuite)' => 0.08,
                'Beurre doux' => 0.025,
                'Ail (gousse)' => 0.8,
                'Persil (botte)' => 0.2,
                'Échalote' => 0.004,
                'Piment frais' => 0.001,
                'Poivre noir' => 0.001,
                'Chapelure panko' => 0.005,
                'Baguette' => 0.2,
            ],
            'etapes' => [
                'Rincer les escargots précuits, retirer les parties dures et la partie sombre si nécessaire. Couper les plus gros morceaux en bouchées de 15 à 20 g. Les blanchir 2 min à l’eau bouillante salée et égoutter.',
                'Hacher finement l’ail, les échalotes et le piment. Ciseler le persil.',
                'Travailler le beurre en pommade et y incorporer l’ail, les échalotes, le persil, le piment, le sel et le poivre.',
                'Répartir les escargots dans 10 cassolettes. Couvrir chacune de 25 g de beurre persillé et saupoudrer de panko.',
                'Enfourner 8 à 10 min à 220 °C, ou passer au four à bois, jusqu’à ce que le beurre bouillonne et que le panko soit doré.',
                'Servir brûlant avec des tranches de baguette grillées pour saucer.',
            ],
        ],
        [
            'produit' => 'Plantain vapeur, tombée d’épinards et croustillant de noix de cajou',
            'categorie' => 'Entrées',
            'description' => 'Entrée végétarienne',
            'lignes' => [
                'Banane plantain' => 0.25,
                'Épinards (botte)' => 0.6,
                'Oignon' => 0.007,
                'Ail (gousse)' => 0.3,
                'Piment frais' => 0.001,
                'Huile végétale' => 0.004,
                'Beurre doux' => 0.005,
                'Cube d\'assaisonnement' => 0.1,
                'Noix de cajou' => 0.01,
                'Chapelure panko' => 0.003,
                'Paprika' => 0.001,
            ],
            'etapes' => [
                'Couper les extrémités des plantains et les tronçonner en deux, sans les éplucher. Cuire 20 à 25 min à la vapeur, jusqu’à ce que la chair soit tendre. Éplucher et tailler en tronçons biseautés.',
                'Équeuter les épinards, les laver à grande eau et les essorer. Émincer l’oignon, hacher l’ail et le piment.',
                'Faire suer l’oignon dans l’huile 3 min, ajouter l’ail et le piment, puis les épinards et le cube émietté. Cuire à feu vif 3 à 4 min en remuant jusqu’à ce qu’ils tombent. Égoutter, ajouter le beurre et rectifier le sel.',
                'Torréfier les noix de cajou à sec, puis les concasser grossièrement. Dorer le panko dans le beurre, ajouter les cajous et le paprika, saler légèrement.',
                'Dresser la tombée d’épinards au centre de l’assiette, disposer les tronçons de plantain chauds dessus et parsemer généreusement de croustillant de cajou.',
            ],
        ],
        [
            'produit' => 'Rillettes aux deux saumons',
            'categorie' => 'Entrées',
            'description' => 'Saumon frais et saumon fumé, mesclun et pain grillé',
            'lignes' => [
                'Saumon frais' => 0.04,
                'Saumon fumé' => 0.02,
                'Beurre doux' => 0.01,
                'Crème liquide entière' => 0.005,
                'Échalote' => 0.004,
                'Cébette (botte)' => 0.05,
                'Citron vert' => 0.005,
                'Piment frais' => 0.001,
                'Laurier (feuille)' => 0.1,
                'Poivre noir' => 0.001,
                'Mesclun' => 0.02,
                'Huile d’olive' => 0.003,
                'Vinaigre' => 0.001,
                'Baguette' => 0.2,
            ],
            'etapes' => [
                'Pocher le saumon frais 8 min dans une eau frémissante salée avec le laurier et quelques grains de poivre. Égoutter, laisser refroidir et effeuiller à la fourchette en retirant les arêtes.',
                'Tailler le saumon fumé en petits dés. Ciseler finement les échalotes et la cébette, hacher le piment.',
                'Travailler le beurre en pommade avec la crème. Ajouter le saumon effeuillé, le saumon fumé, les échalotes, la cébette, le piment, le zeste et le jus de citron vert. Mélanger sans écraser pour garder des morceaux.',
                'Rectifier le sel et le poivre, en tenant compte du sel du saumon fumé. Filmer et réserver au moins 2 h au froid.',
                'Assaisonner le mesclun avec l’huile d’olive, le vinaigre, le sel et le poivre au dernier moment.',
                'Dresser une quenelle de 80 g de rillettes sur un lit de mesclun, avec 2 tranches de baguette grillées.',
            ],
        ],
        [
            'produit' => 'Tartare de tomate et œuf poché',
            'categorie' => 'Entrées',
            'description' => 'Œuf poché coulant, tuile de parmesan et œufs de poisson',
            'lignes' => [
                'Tomate' => 0.18,
                'Oignon rouge' => 0.007,
                'Cébette (botte)' => 0.05,
                'Persil (botte)' => 0.05,
                'Câpres' => 0.002,
                'Piment frais' => 0.001,
                'Huile d’olive' => 0.006,
                'Vinaigre' => 0.007,
                'Poivre noir' => 0.001,
                'Œufs' => 1,
                'Parmesan' => 0.01,
                'Œufs de poisson noirs' => 0.003,
            ],
            'etapes' => [
                'Monder les tomates : inciser la base en croix, plonger 15 secondes dans l’eau bouillante, puis dans l’eau glacée et retirer la peau. Couper en quatre, épépiner et tailler la chair en petits dés réguliers.',
                'Égoutter les dés 15 min dans une passoire pour retirer l’excès d’eau.',
                'Ciseler finement l’oignon rouge, la cébette et le persil. Hacher les câpres et le piment.',
                'Au dernier moment, assaisonner les tomates avec l’oignon, les herbes, les câpres, le piment, l’huile d’olive, le vinaigre, le sel et le poivre.',
                'Porter une grande casserole d’eau à frémissement avec le vinaigre, sans sel. Casser chaque œuf dans un ramequin, créer un tourbillon et y glisser l’œuf. Pocher 3 min, puis égoutter sur papier absorbant.',
                'Pour les tuiles, former 10 disques fins de 10 g de parmesan râpé sur une plaque garnie de papier cuisson. Cuire 5 à 6 min à 180 °C jusqu’à coloration blonde. Laisser durcir à plat et conserver au sec.',
                'Dresser le tartare dans un cercle de 8 cm, déposer l’œuf poché dessus, saler et poivrer l’œuf. Poser une mini cuillère d’œufs de poisson noirs (3 g) sur l’œuf, piquer la tuile de parmesan dans le tartare et servir aussitôt.',
            ],
        ],
        [
            'produit' => 'Tataki de thon rouge',
            'categorie' => 'Entrées',
            'description' => 'Thon saisi en croûte de sésame blanc et noir, sauce soja et citron vert',
            'lignes' => [
                'Thon rouge' => 0.16,
                'Sésame blanc' => 0.003,
                'Sésame noir' => 0.003,
                'Huile végétale' => 0.002,
                'Sauce soja' => 0.01,
                'Citron vert' => 0.015,
                'Gingembre frais' => 0.003,
                'Miel' => 0.002,
                'Piment frais' => 0.001,
                'Concombre' => 0.03,
                'Avocat' => 0.05,
                'Jeunes pousses' => 0.01,
                'Cébette (botte)' => 0.05,
            ],
            'etapes' => [
                'Lever et parer la longe : retirer la peau, la partie sombre et les nerfs. Tailler des pavés rectangulaires de 4 cm de section. Garder au froid jusqu’à la cuisson.',
                'Pour la sauce, mélanger la sauce soja, le jus et le zeste des citrons verts, le gingembre râpé, le miel et le piment haché finement.',
                'Mélanger les sésames blanc et noir. Huiler légèrement les pavés et les rouler dans le mélange de sésame blanc et noir en appuyant pour bien faire adhérer.',
                'Saisir dans une poêle très chaude, 20 secondes par face : le cœur doit rester cru. Plonger aussitôt dans un bac sur glace pour stopper la cuisson, puis essuyer.',
                'Tailler le concombre en fines lamelles et l’avocat en tranches, citronnées pour éviter qu’il noircisse. Ciseler la cébette.',
                'Trancher le thon en tranches de 5 mm au couteau bien aiguisé. Dresser en éventail sur le concombre et l’avocat, napper de sauce et parsemer de cébette. Ajouter une petite poignée de jeunes pousses (10 g) assaisonnées d’un trait de sauce.',
            ],
        ],
        [
            'produit' => 'Œuf en folie',
            'categorie' => 'Entrées',
            'description' => 'Œuf mimosa revisité, ratatouille froide et mayonnaise curry, citron, piment',
            'lignes' => [
                'Œufs' => 1.6,
                'Courgette' => 0.03,
                'Aubergine' => 0.03,
                'Poivron' => 0.02,
                'Tomate' => 0.03,
                'Oignon' => 0.007,
                'Ail (gousse)' => 0.4,
                'Huile d’olive' => 0.005,
                'Thym séché' => 0.001,
                'Laurier (feuille)' => 0.1,
                'Moutarde' => 0.001,
                'Huile végétale' => 0.015,
                'Vinaigre' => 0.001,
                'Curry en poudre' => 0.001,
                'Citron vert' => 0.005,
                'Piment frais' => 0.001,
                'Cébette (botte)' => 0.05,
                'Poivre noir' => 0.001,
                'Chapelure panko' => 0.004,
                'Beurre doux' => 0.002,
                'Poudre de kankankan' => 0.001,
                'Paprika' => 0.001,
                'Pousses de germes' => 0.001,
            ],
            'etapes' => [
                'Pour la ratatouille, tailler courgettes, aubergine, poivrons et tomates mondées en petits dés de 5 mm. Ciseler l’oignon et hacher l’ail.',
                'Faire suer l’oignon dans l’huile d’olive, ajouter les poivrons et l’aubergine 5 min, puis les courgettes, les tomates, l’ail, le thym et le laurier. Cuire à feu doux 20 min, jusqu’à ce que les légumes soient fondants mais encore en dés. Saler, poivrer, retirer le laurier et refroidir complètement.',
                'Pour la sauce, monter une mayonnaise : fouetter le jaune avec la moutarde et le vinaigre, puis verser l’huile en filet fin sans cesser de fouetter. Ajouter l’ail râpé, le curry, le zeste et le jus de citron vert et le piment haché très finement. Saler et réserver au froid.',
                'Cuire les œufs 10 min dans l’eau frémissante, puis les plonger aussitôt dans l’eau glacée. Les écaler et les couper en deux dans la longueur.',
                'Écraser finement les jaunes cuits. Les lier avec la moitié de la sauce curry à l’ail, ajouter la cébette ciselée. Saler, poivrer : la farce doit être lisse et relevée. Garnir les blancs à la poche à douille cannelée.',
                'Pour le croustillant, faire dorer le panko dans le beurre mousseux. Hors du feu, ajouter le kankankan et le paprika. Laisser refroidir.',
                'Dresser un tapis de ratatouille froide au cercle, poser 3 demi-œufs dessus, tracer quelques points de sauce curry à l’ail. Parsemer de croustillant et décorer de 3 pousses de germes.',
            ],
        ],
        [
            'produit' => 'Bokosseu au poisson',
            'categorie' => 'Plats',
            'description' => 'Poisson frais mijoté, sauce aubergine et tomate pimentée',
            'lignes' => [
                'Tilapia' => 0.4,
                'Aubergine' => 0.06,
                'Piment frais' => 0.006,
                'Cube d\'assaisonnement' => 0.4,
                'Tomate' => 0.08,
                'Oignon' => 0.045,
                'Concentré de tomate' => 0.007,
                'Huile végétale' => 0.015,
            ],
            'etapes' => [
                'Laver soigneusement les légumes. Écailler, vider et rincer les poissons, puis les inciser de deux entailles sur chaque face.',
                'Cuire les aubergines et les piments de la sauce à l’eau bouillante jusqu’à ce qu’ils soient tendres, puis les égoutter et les écraser en purée.',
                'Mettre la purée d’aubergines dans une marmite avec l’eau et faire bouillir 15 min. Assaisonner avec les cubes et le sel, laisser cuire encore 5 min, puis arrêter le feu.',
                'Écraser ou mixer grossièrement les tomates, les oignons et les piments. Mélanger avec le concentré de tomate, l’huile, les cubes émiettés et le sel.',
                'Ranger les poissons dans une large casserole sans les superposer. Verser le mélange de tomate dessus et cuire à couvert à feu doux 20 min, sans remuer pour ne pas briser les poissons.',
                'Dresser chaque poisson nappé de sa sauce tomate, avec la sauce d’aubergines à côté. Servir chaud avec l’accompagnement au choix, attiéké ou riz.',
            ],
        ],
        [
            'produit' => 'Bol de nouilles sautées et son Poulet du Chef',
            'categorie' => 'Plats',
            'description' => 'Nouilles au wok, poulet laqué soja, miel et gingembre',
            'lignes' => [
                'Poulet entier' => 0.2,
                'Sauce soja' => 0.012,
                'Sauce huître' => 0.008,
                'Miel' => 0.003,
                'Gingembre frais' => 0.003,
                'Ail (gousse)' => 0.4,
                'Piment frais' => 0.001,
                'Huile végétale' => 0.009,
                'Nouilles aux œufs' => 0.08,
                'Carotte' => 0.03,
                'Poivron' => 0.03,
                'Courgette' => 0.03,
                'Oignon' => 0.015,
                'Cébette (botte)' => 0.1,
                'Sésame blanc' => 0.002,
            ],
            'etapes' => [
                'Désosser les poulets, retirer la peau et tailler la chair en lanières régulières. Mariner 2 h au froid avec la sauce soja, la sauce huître, le miel, le gingembre râpé, l’ail haché et le piment.',
                'Tailler les carottes, les poivrons et les courgettes en julienne, émincer l’oignon, tailler la cébette en biseau en séparant le blanc du vert.',
                'Cuire les nouilles à l’eau bouillante selon le temps indiqué, les refroidir, égoutter et huiler légèrement.',
                'Saisir le poulet égoutté au wok très chaud dans l’huile, par petites quantités, 3 à 4 min jusqu’à ce qu’il soit laqué. Ajouter la marinade en fin de cuisson pour napper. Réserver.',
                'Dans le wok, sauter l’oignon, le blanc de cébette, les carottes, puis les poivrons et les courgettes 2 à 3 min : les légumes doivent rester croquants. Ajouter les nouilles, la sauce soja et la sauce huître, sauter 2 min.',
                'Dresser les nouilles en bol, poser le Poulet du Chef dessus, parsemer de vert de cébette et de sésame torréfié.',
            ],
        ],
        [
            'produit' => 'Boulettes de poisson au citron',
            'categorie' => 'Plats',
            'description' => 'Boulettes de mostelle panées au panko, sauce tartare',
            'lignes' => [
                'Filet de mostelle' => 0.12,
                'Oignon' => 0.007,
                'Ail (gousse)' => 0.4,
                'Persil (botte)' => 0.1,
                'Cébette (botte)' => 0.2,
                'Citron vert' => 0.02,
                'Piment frais' => 0.002,
                'Œufs' => 0.3,
                'Baguette' => 0.1,
                'Cube d\'assaisonnement' => 0.2,
                'Poivre noir' => 0.001,
                'Farine de blé' => 0.01,
                'Chapelure panko' => 0.025,
                'Huile végétale' => 0.03,
            ],
            'etapes' => [
                'Retirer la peau et les arêtes restantes des filets de Mostelle. Ciseler finement les cébettes, blanc et vert.',
                'Tremper la mie de pain dans un peu d’eau et l’essorer.',
                'Hacher la chair au couteau ou au hachoir, grille moyenne. Ajouter l’oignon, l’ail et le piment hachés finement, le persil ciselé, le zeste et le jus de 4 citrons verts, les œufs, la mie essorée, les cébettes, les cubes émiettés et le poivre. Saler et mélanger sans trop travailler.',
                'Réserver l’appareil 30 min au froid, puis façonner 40 boulettes de 30 g à mains humides.',
                'Paner à l’anglaise : passer chaque boulette dans la farine, puis dans l’œuf battu, puis dans la chapelure panko en appuyant légèrement pour bien la faire adhérer. Réserver au froid jusqu’au service.',
                'Frire à 170 °C pendant 4 à 5 min, jusqu’à ce que la panure soit bien dorée et croustillante. Égoutter sur papier absorbant.',
                'Servir 4 boulettes par assiette, accompagnées de sauce tartare (voir fiche Sauce tartare).',
            ],
        ],
        [
            'produit' => 'Brochettes de bœuf au kankankan',
            'categorie' => 'Plats',
            'description' => 'Filet de bœuf grillé au feu de bois',
            'lignes' => [
                'Filet de bœuf' => 0.12,
                'Oignon' => 0.022,
                'Ail (gousse)' => 0.2,
                'Gingembre frais' => 0.003,
                'Moutarde' => 0.002,
                'Huile végétale' => 0.006,
                'Poudre de kankankan' => 0.01,
                'Tomate' => 0.02,
                'Piment frais' => 0.002,
            ],
            'etapes' => [
                'Mixer l’oignon, l’ail et le gingembre avec l’huile et la moutarde. Ajouter un tiers de la poudre de kankankan et saler légèrement.',
                'Tailler le filet en cubes réguliers de 20 g environ. Enrober de marinade et laisser mariner 2 h au froid.',
                'Monter 20 brochettes de 3 cubes, soit 60 g de viande par brochette. Si les pics sont en bois, les faire tremper 30 min dans l’eau au préalable.',
                'Griller sur braise vive au feu de bois, 1 à 2 min par face, pour une cuisson rosée.',
                'À la sortie du feu, rouler les brochettes dans le reste de la poudre de kankankan.',
                'Dresser 2 brochettes par assiette avec l’oignon émincé, les quartiers de tomate et le piment frais. Servir aussitôt.',
            ],
        ],
        [
            'produit' => 'Burger Raclette',
            'categorie' => 'Plats',
            'description' => 'Steak haché 200 g, oignons caramélisés, raclette, sauce cocktail',
            'lignes' => [
                'Bœuf haché' => 0.2,
                'Pain burger brioché' => 1,
                'Huile végétale' => 0.003,
                'Poivre noir' => 0.001,
                'Oignon' => 0.1,
                'Beurre doux' => 0.004,
                'Sucre roux' => 0.003,
                'Vinaigre' => 0.002,
                'Thym séché' => 0.001,
                'Fromage à raclette' => 0.06,
            ],
            'sous_recettes' => [
                'Sauce cocktail' => 0.03,
            ],
            'etapes' => [
                'Pour la tombée, émincer finement les oignons. Les faire fondre dans le beurre à feu doux 30 à 40 min en remuant, jusqu’à coloration brune. Ajouter le sucre et le thym, caraméliser 5 min, déglacer au vinaigre et saler. Réserver au chaud.',
                'Façonner 10 steaks de 200 g, un peu plus larges que le pain, sans trop tasser la viande. Creuser légèrement le centre au pouce pour éviter qu’ils gonflent. Réserver au froid.',
                'Préparer la sauce cocktail selon sa fiche.',
                'Saler et poivrer les steaks, les saisir à la plancha ou sur grille très chaude 3 à 4 min par face selon la cuisson demandée. Une minute avant la fin, poser 2 tranches de raclette sur chaque steak et couvrir pour qu’elles fondent et nappent la viande.',
                'Toaster les pains côté mie.',
                'Monter le burger : pain du bas tartiné de sauce cocktail, steak à la raclette, tombée d’oignons caramélisés, sauce cocktail sur le pain du haut. Piquer et servir aussitôt.',
            ],
        ],
        [
            'produit' => 'Burger du Boucher',
            'categorie' => 'Plats',
            'description' => 'Steak haché 200 g, oignons caramélisés, cheddar, aïoli',
            'lignes' => [
                'Bœuf haché' => 0.2,
                'Pain burger brioché' => 1,
                'Huile végétale' => 0.003,
                'Poivre noir' => 0.001,
                'Oignon' => 0.1,
                'Beurre doux' => 0.004,
                'Sucre roux' => 0.003,
                'Vinaigre' => 0.002,
                'Thym séché' => 0.001,
                'Cheddar' => 0.04,
            ],
            'sous_recettes' => [
                'Sauce aïoli' => 0.03,
            ],
            'etapes' => [
                'Pour la tombée, émincer finement les oignons. Les faire fondre dans le beurre à feu doux 30 à 40 min en remuant, jusqu’à coloration brune. Ajouter le sucre et le thym, caraméliser 5 min, déglacer au vinaigre et saler. Réserver au chaud.',
                'Façonner 10 steaks de 200 g, un peu plus larges que le pain, sans trop tasser la viande. Creuser légèrement le centre au pouce pour éviter qu’ils gonflent. Réserver au froid.',
                'Préparer la sauce aïoli selon sa fiche.',
                'Saler et poivrer les steaks, les saisir à la plancha ou sur grille très chaude 3 à 4 min par face selon la cuisson demandée. Une minute avant la fin, poser 2 tranches de cheddar sur chaque steak et couvrir pour les faire fondre.',
                'Toaster les pains côté mie.',
                'Monter le burger : pain du bas tartiné d’aïoli, steak au cheddar, tombée d’oignons caramélisés, aïoli sur le pain du haut. Piquer et servir aussitôt.',
            ],
        ],
        [
            'produit' => 'Crabe en brochette',
            'categorie' => 'Plats',
            'description' => 'Crabe mariné grillé au feu de bois',
            'lignes' => [
                'Crabe précuit' => 0.2,
                'Ail (gousse)' => 0.6,
                'Gingembre frais' => 0.002,
                'Citron vert' => 0.03,
                'Piment frais' => 0.002,
                'Paprika' => 0.001,
                'Persil (botte)' => 0.1,
                'Huile végétale' => 0.008,
                'Cube d\'assaisonnement' => 0.2,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Nettoyer les crabes, retirer la carapace dorsale et les branchies. Couper chaque corps en quatre et fêler les pinces pour faciliter la dégustation.',
                'Mixer l’ail, le gingembre et le piment avec l’huile, le jus et le zeste des citrons verts, le paprika et les cubes émiettés. Ajouter le persil ciselé et le poivre.',
                'Enrober les morceaux de crabe de marinade et laisser mariner 1 h au froid.',
                'Monter 20 brochettes en alternant morceaux de corps et pinces. Si les pics sont en bois, les tremper 30 min dans l’eau au préalable.',
                'Le crabe étant précuit, griller sur braise vive 2 à 3 min par face pour le réchauffer à cœur et colorer, en badigeonnant du reste de marinade.',
                'Servir aussitôt avec les quartiers de citron vert.',
            ],
        ],
        [
            'produit' => 'Côte de porc marinée',
            'categorie' => 'Plats',
            'description' => 'Côte grillée au feu de bois, laquée au miel',
            'lignes' => [
                'Côte de porc' => 0.25,
                'Miel' => 0.01,
                'Sauce soja' => 0.006,
                'Moutarde' => 0.004,
                'Ail (gousse)' => 0.4,
                'Gingembre frais' => 0.002,
                'Citron vert' => 0.01,
                'Paprika' => 0.001,
                'Poivre noir' => 0.001,
                'Huile végétale' => 0.004,
            ],
            'etapes' => [
                'Inciser le gras des côtes tous les 3 cm pour éviter qu’elles se rétractent à la cuisson.',
                'Mélanger le miel, la sauce soja, la moutarde, l’ail écrasé, le gingembre râpé, le jus de citron vert, le paprika, le poivre et l’huile.',
                'Réserver un quart de la marinade pour le laquage. Enrober les côtes avec le reste et laisser mariner 4 h au froid.',
                'Égoutter les côtes, saler, puis griller sur braise moyenne 5 à 6 min par face. Le miel caramélise vite : éloigner de la flamme si besoin.',
                'Badigeonner de marinade réservée pendant les 2 dernières minutes. Contrôler 65 °C à cœur.',
                'Laisser reposer 3 min et servir chaud.',
            ],
        ],
        [
            'produit' => 'Demi-langouste grillée',
            'categorie' => 'Plats',
            'description' => 'Grillée au feu de bois, beurre persillé au citron vert',
            'lignes' => [
                'Langouste' => 0.35,
                'Beurre doux' => 0.015,
                'Ail (gousse)' => 0.6,
                'Persil (botte)' => 0.2,
                'Citron vert' => 0.035,
                'Piment frais' => 0.001,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Placer les langoustes 15 à 20 min au congélateur pour les endormir.',
                'Les fendre en deux dans la longueur avec un couteau lourd. Retirer le boyau et la poche à graviers située dans la tête.',
                'Ramollir le beurre et le mélanger avec l’ail haché, le persil ciselé, le zeste et le jus de citron vert, le piment haché finement et le poivre. Saler.',
                'Badigeonner la chair d’une fine couche de beurre persillé.',
                'Griller côté chair sur braise vive 3 à 4 min, puis retourner côté carapace et poursuivre 4 à 5 min, jusqu’à ce que la chair soit nacrée.',
                'Napper du reste de beurre persillé et servir aussitôt avec les quartiers de citron vert.',
            ],
        ],
        [
            'produit' => 'Filet de bœuf sauce au poivre vert et poivre de Penja',
            'categorie' => 'Plats',
            'description' => 'Gratin de pommes de terre aux champignons',
            'lignes' => [
                'Filet de bœuf' => 0.18,
                'Huile végétale' => 0.003,
                'Beurre doux' => 0.01,
                'Échalote' => 0.006,
                'Poivre vert en saumure' => 0.004,
                'Poivre de Penja' => 0.001,
                'Crème liquide entière' => 0.1,
                'Cube d\'assaisonnement' => 0.1,
                'Pomme de terre' => 0.2,
                'Champignons de Paris surgelés' => 0.04,
                'Lait' => 0.04,
                'Ail (gousse)' => 0.3,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Pour le gratin, éplucher les pommes de terre et les trancher en rondelles de 3 mm, sans les laver. Faire sauter les champignons égouttés au beurre jusqu’à évaporation de leur eau.',
                'Porter la crème et le lait à frémissement avec l’ail écrasé, le sel et le poivre. Y plonger les pommes de terre et cuire 10 min à feu doux en remuant délicatement.',
                'Beurrer un plat à gratin, alterner couches de pommes de terre et de champignons, napper du mélange crème et lait. Cuire 1 h à 160 °C, jusqu’à ce que la lame d’un couteau entre sans résistance et que le dessus soit doré.',
                'Pour la sauce, suer les échalotes ciselées au beurre, ajouter le poivre de Penja et le torréfier 1 min. Mouiller avec l’eau et le cube, réduire de moitié. Ajouter la crème et le poivre vert, réduire à consistance nappante. Saler.',
                'Détailler le filet en tournedos de 180 g, les ficeler si besoin. Saler et saisir à l’huile très chaude 2 à 3 min par face, ajouter le beurre et arroser. Cuire selon la commande et laisser reposer 5 min.',
                'Dresser le filet avec une part de gratin, napper la viande de sauce au poivre vert et Penja.',
            ],
        ],
        [
            'produit' => 'Gambas persillade',
            'categorie' => 'Plats',
            'description' => 'Gambas saisies au feu de bois, ail et persil',
            'lignes' => [
                'Gambas' => 0.25,
                'Ail (gousse)' => 1,
                'Persil (botte)' => 0.3,
                'Huile végétale' => 0.01,
                'Beurre doux' => 0.008,
                'Piment frais' => 0.001,
                'Poivre noir' => 0.001,
                'Citron vert' => 0.015,
            ],
            'etapes' => [
                'Inciser les gambas sur le dos avec des ciseaux, sans les décortiquer, et retirer le boyau. Garder la tête.',
                'Hacher finement l’ail, ciseler le persil et hacher le piment. Mélanger avec la moitié de l’huile : c’est la persillade.',
                'Enrober les gambas avec le reste de l’huile, saler et poivrer.',
                'Saisir sur braise vive ou à la plancha 2 à 3 min par face, jusqu’à ce que la carapace soit bien rouge.',
                'Dans une sauteuse, faire mousser le beurre, ajouter la persillade et chauffer 30 secondes sans colorer l’ail. Y rouler les gambas.',
                'Dresser aussitôt avec les quartiers de citron vert.',
            ],
        ],
        [
            'produit' => 'Kedjenou de poulet africain',
            'categorie' => 'Plats',
            'description' => 'Poulet mijoté à l’étouffée dans son jus, tomates, aubergines et piment',
            'lignes' => [
                'Poulet africain entier' => 0.3,
                'Oignon' => 0.022,
                'Tomate' => 0.04,
                'Aubergine' => 0.03,
                'Piment frais' => 0.002,
                'Ail (gousse)' => 0.6,
                'Cube d\'assaisonnement' => 0.3,
                'Huile végétale' => 0.003,
            ],
            'etapes' => [
                'Flamber les poulets pour retirer les derniers duvets, les vider et les découper en morceaux réguliers, avec la peau. Laver et égoutter.',
                'Émincer les oignons, couper les tomates en quartiers et les aubergines en dés. Écraser l’ail et le piment au mortier.',
                'Dans une cocotte en fonte ou un canari, déposer les morceaux de poulet. Ajouter les oignons, les tomates, les aubergines, la pâte d’ail et piment, les cubes émiettés, l’huile et le sel. Mélanger à la main pour bien enrober.',
                'Ajouter l’eau, fermer hermétiquement et cuire à feu doux 1 h à 1 h 15. Ne pas ouvrir : secouer la cocotte toutes les 10 min en la tenant par les anses pour mélanger, afin que le poulet cuise dans son propre jus.',
                'En fin de cuisson, la viande doit être tendre, la sauce courte et les légumes fondus. Rectifier le sel.',
                'Servir chaud dans son jus, avec l’accompagnement au choix, attiéké ou riz.',
            ],
        ],
        [
            'produit' => 'Magret de canard au Porto',
            'categorie' => 'Plats',
            'description' => 'Tatin d’échalotes confites, pommes de terre sarladaises',
            'lignes' => [
                'Magret de canard' => 0.2,
                'Porto rouge' => 0.03,
                'Échalote' => 0.084,
                'Cube d\'assaisonnement' => 0.1,
                'Miel' => 0.002,
                'Beurre doux' => 0.01,
                'Sucre roux' => 0.008,
                'Vinaigre' => 0.002,
                'Thym séché' => 0.001,
                'Pâte feuilletée' => 0.05,
                'Pomme de terre' => 0.2,
                'Ail (gousse)' => 0.4,
                'Persil (botte)' => 0.1,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Pour les tatins, éplucher les échalotes en les laissant entières. Faire un caramel blond avec le sucre et le beurre, déglacer au vinaigre, ajouter les échalotes et le thym. Confire à couvert 25 min à feu doux.',
                'Répartir les échalotes confites et leur caramel dans 10 moules individuels de 8 cm. Couvrir d’un disque de pâte feuilletée piqué, en rentrant les bords. Cuire 20 min à 190 °C et démouler chaud.',
                'Quadriller la peau des magrets sans entailler la chair. Les cuire côté peau à feu moyen 8 à 10 min dans une poêle froide pour faire fondre la graisse. Récupérer la graisse au fur et à mesure.',
                'Retourner les magrets, cuire 3 à 4 min côté chair, puis laisser reposer 8 min sous aluminium. Saler et poivrer.',
                'Pour les sarladaises, éplucher les pommes de terre et les trancher en rondelles de 4 mm. Les faire sauter dans la graisse de magret à feu moyen 20 à 25 min jusqu’à ce qu’elles soient dorées et fondantes. Ajouter l’ail et le persil hachés en fin de cuisson. Saler.',
                'Pour la sauce, dégraisser la poêle, suer les échalotes ciselées, déglacer au Porto et réduire des deux tiers. Ajouter l’eau, le cube et le miel, réduire jusqu’à consistance sirupeuse, puis monter au beurre froid.',
                'Trancher le demi-magret en éventail, napper de sauce au Porto. Dresser la tatin et les sarladaises à côté.',
            ],
        ],
        [
            'produit' => 'Petit-déjeuner INNOPLAZA',
            'categorie' => 'Plats',
            'description' => 'Formule au plateau, puis en buffet',
            'lignes' => [
                'Boule de pain' => 1,
                'Sucre' => 0.01,
                'Mini pancakes (portion de 3)' => 1,
                'Granola maison (portion)' => 1,
                'Yaourt nature' => 0.04,
                'Salade de fruits de saison' => 0.05,
                'Confiture maison' => 0.015,
                'Beurre doux (micro-portion)' => 1,
                'Œufs' => 2,
                'Beurre doux' => 0.005,
                'Jambon de bœuf' => 0.02,
            ],
            'etapes' => [
                'Mise en place la veille : jus et thé glacé préparés et mis au froid, salade de fruits taillée et filmée, granola et confiture portionnés, yaourts en pots, jambon tranché et réservé au froid.',
                'Le matin, cuire les viennoiseries et réchauffer les boules de pain. Préparer la pâte à pancakes 30 min avant le service et cuire les mini pancakes en continu.',
                'Dresser le plateau : viennoiserie et pain en corbeille, micro beurre et confiture en coupelle, yaourt et granola en verrine, salade de fruits en coupelle, jambon roulé sur assiette.',
                'Prendre la commande des œufs et de la boisson chaude. Cuire les œufs à la minute, 2 par personne, et les envoyer avec le jambon et les mini pancakes.',
                'Servir le jus au choix en verre de 20 cl, puis la boisson chaude avec 2 dosettes de sucre.',
                'Pour le passage en buffet, prévoir une fiche dédiée avec les quantités pour 50 couverts. Compter environ 1,4 fois la consommation du plateau, pertes et resservices compris, et surveiller le jambon, les viennoiseries et les jus, qui partent le plus vite.',
            ],
        ],
        [
            'produit' => 'Poitrine de bœuf à l’américaine',
            'categorie' => 'Plats',
            'description' => 'Brisket fumée au feu de bois',
            'lignes' => [
                'Poitrine de bœuf' => 0.417,
                'Moutarde' => 0.007,
                'Sel' => 0.005,
                'Poivre noir' => 0.005,
                'Ail en poudre' => 0.003,
                'Paprika' => 0.003,
                'Sucre roux' => 0.007,
                'Piment en poudre' => 0.001,
                'Jus de pomme' => 0.017,
                'Ketchup' => 0.029,
                'Vinaigre' => 0.004,
                'Sauce soja' => 0.003,
            ],
            'etapes' => [
                'Parer la poitrine : retirer la membrane argentée et le gras dur, en laissant 5 mm à 1 cm de gras de couverture. Arrondir les bords fins qui brûleraient. Compter environ 15 % de perte au parage.',
                'Mélanger le gros sel, le poivre, l’ail en poudre, le paprika, le sucre roux et le piment.',
                'Badigeonner toute la pièce d’une fine couche de moutarde, puis frotter généreusement avec le rub sur toutes les faces. Filmer et laisser reposer une nuit au froid, ou au minimum 1 h à température ambiante avant cuisson.',
                'Préparer le fumoir ou le four à bois à 110 à 120 °C, avec un bac d’eau pour garder l’humidité. Ajouter le bois de fumage en début de cuisson.',
                'Placer la poitrine côté gras vers le haut. Cuire environ 5 h sans ouvrir, puis pulvériser le jus de pomme toutes les heures, jusqu’à 70 °C à cœur et une croûte bien sombre.',
                'Envelopper serré dans du papier aluminium avec un dernier trait de jus de pomme. Poursuivre la cuisson 3 à 4 h jusqu’à 93 à 95 °C à cœur : la sonde doit entrer sans résistance.',
                'Laisser reposer 1 à 2 h dans un caisson isotherme, toujours emballée. Récupérer le jus de cuisson.',
                'Pour la sauce, porter à frémissement le ketchup, le vinaigre, le sucre roux, la moutarde et la sauce soja avec 100 ml de jus de cuisson dégraissé. Réduire 10 min.',
                'Trancher à la minute contre le sens des fibres, en tranches de 6 mm environ. Servir chaud avec la sauce barbecue maison.',
            ],
        ],
        [
            'produit' => 'Poitrine d’agneau braisée',
            'categorie' => 'Plats',
            'description' => 'Braisée au gingembre et aux poivrons',
            'lignes' => [
                'Poitrine d’agneau' => 0.3,
                'Oignon' => 0.022,
                'Ail (gousse)' => 0.6,
                'Gingembre frais' => 0.006,
                'Poivron' => 0.04,
                'Carotte' => 0.03,
                'Concentré de tomate' => 0.006,
                'Thym séché' => 0.001,
                'Laurier (feuille)' => 0.3,
                'Cube d\'assaisonnement' => 0.3,
                'Poivre noir' => 0.001,
                'Huile végétale' => 0.008,
            ],
            'etapes' => [
                'Dégraisser la poitrine en gardant une fine couche de gras. Détailler en morceaux de 300 g. Saler et poivrer.',
                'Émincer les oignons et les poivrons, tailler les carottes en rondelles, hacher l’ail et râper le gingembre.',
                'Dans une cocotte, saisir les morceaux dans l’huile chaude jusqu’à belle coloration sur toutes les faces. Réserver.',
                'Dans la même cocotte, faire suer les oignons, les carottes et les poivrons 10 min. Ajouter l’ail, le gingembre et le concentré de tomate, cuire 2 min.',
                'Remettre la viande, ajouter le thym, le laurier, les cubes émiettés et l’eau à hauteur. Porter à frémissement.',
                'Couvrir et braiser 2 h 30 au four à 160 °C, jusqu’à ce que la viande se détache de l’os.',
                'Retirer la viande. Dégraisser le jus, retirer le laurier et réduire jusqu’à consistance nappante. Rectifier l’assaisonnement.',
                'Servir chaud, nappé du jus et des légumes de braisage.',
            ],
        ],
        [
            'produit' => 'Poulet boucané',
            'categorie' => 'Plats',
            'description' => 'Poulet fumé au feu de bois, citron vert',
            'lignes' => [
                'Poulet entier' => 0.5,
                'Citron vert' => 0.075,
                'Oignon' => 0.022,
                'Ail (gousse)' => 1,
                'Gingembre frais' => 0.006,
                'Piment frais' => 0.003,
                'Moutarde' => 0.006,
                'Huile végétale' => 0.016,
                'Thym séché' => 0.001,
                'Laurier (feuille)' => 0.5,
                'Cube d\'assaisonnement' => 0.5,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Flamber et vider les poulets si nécessaire. Les ouvrir en crapaudine par le dos et les aplatir.',
                'Mixer l’oignon, l’ail, le gingembre et le piment avec le jus et les zestes des citrons verts, la moutarde, l’huile, le thym, les cubes émiettés et le poivre. Saler.',
                'Inciser les cuisses en profondeur. Masser les poulets avec la marinade, y compris sous la peau, et ajouter les feuilles de laurier. Laisser mariner 12 h au froid.',
                'Fumer au feu de bois à 110 à 120 °C, peau vers le haut, pendant environ 1 h 30, en badigeonnant de marinade toutes les 30 min.',
                'Terminer sur braise vive 5 min par face pour colorer la peau. Contrôler 75 °C à cœur au niveau de la cuisse.',
                'Laisser reposer 10 min, couper chaque poulet en deux et servir avec les quartiers de citron vert.',
            ],
        ],
        [
            'produit' => 'Saint-Jacques snackées',
            'categorie' => 'Plats',
            'description' => 'Linguine à l’encre de seiche, beurre blanc',
            'lignes' => [
                'Noix de Saint-Jacques' => 0.125,
                'Huile végétale' => 0.003,
                'Beurre doux' => 0.033,
                'Linguine à l’encre de seiche' => 0.08,
                'Huile d’olive' => 0.003,
                'Échalote' => 0.008,
                'Vin blanc sec' => 0.015,
                'Vinaigre' => 0.005,
                'Crème liquide entière' => 0.005,
                'Citron vert' => 0.005,
                'Poivre noir' => 0.001,
                'Cébette (botte)' => 0.025,
            ],
            'etapes' => [
                'Décongeler les noix la veille au réfrigérateur sur une grille. Retirer le petit muscle latéral, les éponger soigneusement sur papier absorbant.',
                'Pour le beurre blanc, réduire les échalotes ciselées avec le vin blanc et le vinaigre jusqu’à ce qu’il ne reste qu’une cuillère de liquide. Ajouter la crème, porter à ébullition, puis incorporer le beurre froid en parcelles en fouettant à feu doux. Ajouter le jus de citron vert, saler, poivrer, filtrer et maintenir au bain-marie tiède.',
                'Cuire les linguine dans une grande quantité d’eau bouillante salée selon le temps indiqué, al dente. Égoutter et lustrer à l’huile d’olive.',
                'Saler les noix au dernier moment. Les saisir dans une poêle très chaude avec l’huile, 1 min sans y toucher, puis retourner, ajouter le beurre et arroser 30 secondes. Le cœur doit rester nacré.',
                'Dresser un nid de linguine roulé à la fourchette, disposer 5 noix autour, napper de beurre blanc et parsemer de cébette ciselée.',
            ],
        ],
        [
            'produit' => 'Salade César',
            'categorie' => 'Plats',
            'description' => 'Salade plat',
            'lignes' => [
                'Sucrine' => 1,
                'Blanc de poulet' => 0.12,
                'Farine de blé' => 0.008,
                'Œufs' => 1.3,
                'Chapelure panko' => 0.015,
                'Huile végétale' => 0.055,
                'Baguette' => 0.1,
                'Ail (gousse)' => 0.2,
                'Moutarde' => 0.001,
                'Anchois marinés' => 0.001,
                'Parmesan' => 0.013,
                'Citron vert' => 0.005,
                'Poivre noir' => 0.001,
                'Tomate cerise' => 0.03,
            ],
            'etapes' => [
                'Pour la sauce, fouetter le jaune avec la moutarde, l’ail râpé et les anchois écrasés. Monter avec l’huile en filet, puis ajouter le parmesan râpé, le jus de citron vert et le poivre. Saler peu, les anchois et le parmesan sont déjà salés. Réserver au froid.',
                'Cuire les œufs 10 min, les refroidir dans l’eau glacée, écaler et couper en quartiers.',
                'Couper la baguette en cubes de 2 cm. Les faire frire dans l’huile chaude avec la gousse d’ail écrasée jusqu’à coloration dorée. Égoutter et saler.',
                'Tailler le blanc de poulet en aiguillettes, saler, poivrer. Paner à l’anglaise : farine, œufs battus, panko. Frire 4 à 5 min à 170 °C. Égoutter et trancher.',
                'Effeuiller les sucrines, laver et essorer. Couper les tomates cerises en deux.',
                'Mélanger les feuilles de sucrine avec la sauce César. Dresser en assiette creuse, ajouter le poulet pané tiède, les croûtons, les quartiers d’œuf, les tomates cerises et les copeaux de parmesan.',
            ],
        ],
        [
            'produit' => 'Salade de la mer',
            'categorie' => 'Plats',
            'description' => 'Salade plat',
            'lignes' => [
                'Saumon fumé' => 0.04,
                'Anchois marinés' => 0.02,
                'Crevettes' => 0.075,
                'Calamar' => 0.05,
                'Huile d’olive' => 0.01,
                'Ail (gousse)' => 0.2,
                'Pomme de terre' => 0.08,
                'Mesclun' => 0.03,
                'Vinaigre' => 0.002,
                'Citron vert' => 0.005,
                'Moutarde' => 0.001,
                'Cébette (botte)' => 0.05,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Cuire les pommes de terre dans leur peau à l’eau salée 20 à 25 min. Éplucher tièdes et tailler en rondelles ou en quartiers.',
                'Cuire les crevettes 2 min dans une eau bouillante salée, refroidir dans l’eau glacée. Décortiquer en gardant la queue sur 5 pièces par assiette pour le dressage.',
                'Nettoyer le calamar, inciser le corps en croisillons et le tailler en lanières. Mariner 10 min avec l’huile d’olive et l’ail haché, puis griller 1 min par face sur braise ou à la plancha très chaude. Saler.',
                'Préparer la vinaigrette en fouettant la moutarde, le vinaigre, le jus de citron vert, le sel et le poivre, puis l’huile d’olive. Ajouter la cébette ciselée.',
                'Assaisonner le mesclun et les pommes de terre séparément avec une partie de la vinaigrette.',
                'Dresser le mesclun, répartir les pommes de terre, puis le saumon fumé en roses, les anchois, les 5 crevettes et le calamar grillé tiède. Arroser d’un filet de vinaigrette.',
            ],
        ],
        [
            'produit' => 'Sole meunière',
            'categorie' => 'Plats',
            'description' => 'Beurre noisette citronné, pommes de terre château',
            'lignes' => [
                'Sole' => 0.4,
                'Farine de blé' => 0.01,
                'Huile végétale' => 0.009,
                'Beurre doux' => 0.031,
                'Citron jaune' => 0.015,
                'Persil (botte)' => 0.1,
                'Poivre noir' => 0.001,
                'Pomme de terre' => 0.25,
                'Thym séché' => 0.001,
                'Laurier (feuille)' => 0.2,
            ],
            'etapes' => [
                'Peler les soles côté noir et écailler côté blanc. Ébarber, vider et retirer la tête en biais. Rincer et éponger.',
                'Tourner les pommes de terre en forme de gros bouchon de 5 cm à 7 pans. Les blanchir 5 min à l’eau salée, égoutter.',
                'Faire rissoler les pommes de terre dans l’huile et le beurre avec le thym et le laurier, puis finir au four à 180 °C 20 à 25 min en les retournant, jusqu’à ce qu’elles soient dorées et fondantes. Saler.',
                'Saler et poivrer les soles, les fariner et tapoter pour retirer l’excédent. Les cuire dans l’huile et une noix de beurre, côté blanc d’abord, 4 à 5 min par face. Dresser sur assiette chaude.',
                'Jeter la graisse de cuisson, faire mousser le reste du beurre jusqu’à coloration noisette, ajouter le jus de citron et le persil ciselé.',
                'Napper la sole de beurre meunière bouillant, dresser les pommes château à côté et servir aussitôt.',
            ],
        ],
        [
            'produit' => 'Travers de porc barbecue',
            'categorie' => 'Plats',
            'description' => 'Ribs marinés et glacés au four',
            'lignes' => [
                'Ribs de porc' => 0.375,
                'Oignon' => 0.019,
                'Ail (gousse)' => 0.5,
                'Paprika' => 0.002,
                'Cumin' => 0.002,
                'Moutarde' => 0.004,
                'Sucre roux' => 0.012,
                'Vinaigre' => 0.004,
                'Sauce huître' => 0.004,
                'Sauce soja' => 0.004,
                'Ketchup' => 0.015,
                'Cube d\'assaisonnement' => 0.5,
            ],
            'etapes' => [
                'Émincer l’oignon et écraser l’ail. Dans un bol, mélanger l’oignon, l’ail, le paprika, le cumin, la moutarde, le sucre roux, le vinaigre, la sauce huître, la sauce soja, le ketchup et les cubes émiettés. Saler et poivrer.',
                'Réserver un tiers de la marinade au froid pour le glaçage final.',
                'Disposer les ribs dans un plat allant au four, napper avec le reste de la marinade, couvrir de papier aluminium et laisser mariner 1 h au réfrigérateur.',
                'Préchauffer le four à 180 °C.',
                'Retirer le papier aluminium et cuire les ribs 2 h en les retournant toutes les 30 min.',
                'Badigeonner avec la marinade réservée et griller 10 min pour obtenir une coloration caramélisée.',
                'Servir chaud avec la sauce barbecue maison.',
            ],
        ],
        [
            'produit' => 'Viande à la broche',
            'categorie' => 'Plats',
            'description' => 'Épaule de mouton marinée, rôtie à la broche au feu de bois',
            'lignes' => [
                'Épaule de mouton' => 0.25,
                'Marinade feu de bois' => 0.03,
                'Oignon rouge' => 0.015,
                'Piment frais' => 0.001,
                'Citron vert' => 0.015,
            ],
            'etapes' => [
                'Dégraisser légèrement l’épaule en gardant une fine couche de gras qui nourrit la viande à la cuisson. Inciser la chair en profondeur tous les 3 cm.',
                'Masser l’épaule avec la marinade feu de bois, en la faisant pénétrer dans les incisions. Filmer et laisser mariner 12 h au froid.',
                'Sortir la viande 1 h avant la cuisson. L’embrocher dans l’axe de l’os et la ficeler serré pour une rotation régulière.',
                'Rôtir à la broche devant les braises, à bonne distance, 1 h 30 à 2 h, en arrosant de marinade toutes les 20 min. La viande doit être dorée en surface et se détacher de l’os, à 75 °C à cœur.',
                'Laisser reposer 10 min, puis découper en morceaux de 250 g avec os.',
                'Servir avec l’oignon rouge émincé, le piment haché et les quartiers de citron vert.',
            ],
        ],
        [
            'produit' => 'Attiéké',
            'categorie' => 'Accompagnements',
            'description' => null,
            'lignes' => [
                'Attiéké (semoule de manioc)' => 0.3,
            ],
            'etapes' => [
                'Peser 300 g de semoule de manioc (attiéké).',
                'Chauffer à la vapeur dans un cuit-vapeur ou au micro-ondes (2 à 3 min) jusqu\'à obtenir une texture légère et bien égrainée.',
                'Égrainer délicatement à la fourchette pour bien séparer les grains si nécessaire.',
                'Servir chaud en accompagnement.',
            ],
        ],
        [
            'produit' => 'Frites épicées',
            'categorie' => 'Accompagnements',
            'description' => null,
            'lignes' => [
                'Pomme de terre' => 0.2,
                'Gingembre en poudre' => 0.005,
                'Paprika fumé' => 0.005,
                'Ail en poudre' => 0.005,
                'Oignon en poudre' => 0.005,
                'Persil séché' => 0.01,
            ],
            'etapes' => [
                'Éplucher les pommes de terre et les tailler en bâtonnets réguliers.',
                'Plonger les bâtonnets dans l\'eau froide pour les rincer et éliminer l\'amidon, puis sécher soigneusement.',
                'Premier bain d\'huile à 155°C pendant 10 min (cuisson à cœur, sans coloration).',
                'Égoutter et laisser reposer quelques minutes.',
                'Deuxième bain d\'huile à 180°C pendant 5 min (dorure et croustillant).',
                'Égoutter sur papier absorbant.',
                'Assaisonner immédiatement avec sel, gingembre, paprika fumé, ail, oignon en poudre et persil séché de manière homogène.',
                'Servir chaud.',
            ],
        ],
        [
            'produit' => 'Pomme de terre au four & Sauce crème',
            'categorie' => 'Accompagnements',
            'description' => null,
            'lignes' => [
                'Pomme de terre' => 0.3,
            ],
            'sous_recettes' => [
                'Sauce crème Signature' => 0.05,
            ],
            'etapes' => [
                'Laver soigneusement les pommes de terre sans les éplucher.',
                'Envelopper chaque pomme de terre individuellement dans du papier d\'aluminium.',
                'Cuire au four à 200°C pendant 45 à 60 minutes selon la taille.',
                'Vérifier la cuisson en piquant avec la pointe d\'un couteau (lame doit glisser sans résistance).',
                'Déballer, inciser légèrement le dessus en croix.',
                'Servir avec de la fleur de sel, un tour de moulin à poivre et 50 g de sauce crème à part.',
            ],
        ],
        [
            'produit' => 'Riz du Chef',
            'categorie' => 'Accompagnements',
            'description' => 'Accompagnement au choix des planches',
            'lignes' => [
                'Riz thaï' => 0.1,
                'Oignon' => 0.03,
                'Ail (gousse)' => 0.3,
                'Persil (botte)' => 0.2,
                'Poivron' => 0.03,
                'Poireau' => 0.02,
                'Céleri' => 0.01,
                'Carotte' => 0.03,
                'Navet' => 0.02,
                'Courgette' => 0.02,
                'Curcuma' => 0.005,
                'Laurier (feuille)' => 0.3,
                'Concentré de tomate' => 0.005,
                'Huile végétale' => 0.02,
            ],
            'etapes' => [
                'Rincer le riz à l\'eau froide 4 à 5 fois, jusqu\'à ce que l\'eau soit claire. Égoutter.',
                'Tailler les carottes, le navet et les courgettes en brunoise, émincer les poireaux, hacher le céleri, les oignons et l\'ail. Mixer finement le persil et les poivrons.',
                'Faire revenir dans un quart de l\'huile les carottes, le navet, le céleri, les poireaux puis les courgettes. Réserver.',
                'Dans la même casserole, ajouter le reste de l\'huile, puis les oignons, le poivron, l\'ail et les feuilles de laurier. Cuire 10 min.',
                'Ajouter le persil et cuire 5 min.',
                'Ajouter le riz et le faire griller jusqu\'à légère coloration.',
                'Ajouter le curcuma et le concentré de tomate, puis l\'eau chaude jusqu\'à un doigt au-dessus du riz. Saler.',
                'Cuire 15 min, puis remuer. À mi-cuisson, quand il reste 10 min, ajouter les légumes réservés et poursuivre la cuisson 10 min.',
                'Retirer les feuilles de laurier et servir chaud.',
            ],
        ],
        [
            'produit' => 'Riz noir pilaf',
            'categorie' => 'Accompagnements',
            'description' => 'Accompagnement',
            'lignes' => [
                'Riz noir' => 0.06,
                'Oignon' => 0.007,
                'Beurre doux' => 0.004,
                'Huile végétale' => 0.002,
                'Cube d\'assaisonnement' => 0.2,
                'Laurier (feuille)' => 0.1,
                'Thym séché' => 0.001,
            ],
            'etapes' => [
                'Rincer le riz noir à l’eau froide et l’égoutter.',
                'Ciseler finement l’oignon et le faire suer dans la moitié du beurre et l’huile, sans coloration.',
                'Ajouter le riz et le nacrer 2 min en remuant, jusqu’à ce que les grains soient bien enrobés.',
                'Mouiller avec l’eau bouillante et les cubes émiettés, ajouter le laurier et le thym. Saler légèrement.',
                'Couvrir et cuire 35 à 40 min à feu très doux, ou au four à 180 °C, jusqu’à absorption complète du liquide. Le grain doit rester légèrement ferme.',
                'Retirer le laurier, égrener à la fourchette avec le reste du beurre. Rectifier l’assaisonnement.',
            ],
        ],
        [
            'produit' => 'Sauce aïoli',
            'categorie' => 'Sauces',
            'description' => 'Émulsion à l’ail et au citron vert',
            'lignes' => [
                'Ail (gousse)' => 0.6,
                'Œufs' => 0.1,
                'Moutarde' => 0.001,
                'Huile végétale' => 0.02,
                'Huile d’olive' => 0.005,
                'Citron vert' => 0.005,
            ],
            'etapes' => [
                'Éplucher l’ail, retirer le germe et l’écraser au mortier avec une pincée de sel jusqu’à obtenir une pâte lisse.',
                'Ajouter le jaune d’œuf et la moutarde, mélanger. Tous les ingrédients doivent être à température ambiante.',
                'Monter en versant l’huile neutre en filet fin, puis l’huile d’olive, sans cesser de fouetter.',
                'Détendre avec le jus de citron vert et rectifier le sel.',
                'Filmer au contact et réserver au froid. Préparée avec un jaune cru, la sauce se conserve 24 h maximum entre 0 et 3 °C.',
            ],
        ],
        [
            'produit' => 'Sauce champignons',
            'categorie' => 'Sauces',
            'description' => 'Champignons, échalote et crème',
            'lignes' => [
                'Champignons de Paris surgelés' => 0.03,
                'Échalote' => 0.006,
                'Ail (gousse)' => 0.1,
                'Beurre doux' => 0.003,
                'Crème liquide entière' => 0.03,
                'Cube d\'assaisonnement' => 0.1,
                'Thym séché' => 0.001,
                'Persil (botte)' => 0.025,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Décongeler les champignons au réfrigérateur la veille, ou les cuire directement surgelés. Les égoutter soigneusement. Ciseler les échalotes et hacher l’ail.',
                'Faire fondre le beurre dans une sauteuse. Suer les échalotes 2 min sans coloration, ajouter l’ail.',
                'Ajouter les champignons et les faire sauter à feu vif jusqu’à évaporation complète de leur eau et légère coloration.',
                'Mouiller avec l’eau et le cube émietté, ajouter le thym. Réduire de moitié.',
                'Ajouter la crème et laisser réduire à feu doux 8 à 10 min, jusqu’à ce que la sauce nappe la cuillère.',
                'Rectifier l’assaisonnement, ajouter le persil ciselé au dernier moment. Maintenir au chaud au bain-marie pour le service.',
            ],
        ],
        [
            'produit' => 'Sauce chimichurri',
            'categorie' => 'Sauces',
            'description' => 'Herbes fraîches, ail, piment et vinaigre',
            'lignes' => [
                'Persil (botte)' => 0.2,
                'Coriandre (botte)' => 0.1,
                'Ail (gousse)' => 0.4,
                'Piment frais' => 0.001,
                'Origan séché' => 0.001,
                'Vinaigre' => 0.006,
                'Huile d’olive' => 0.015,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Laver et sécher soigneusement le persil et la coriandre. Les hacher finement au couteau, sans les mixer, pour garder de la texture.',
                'Hacher très finement l’ail et le piment épépiné.',
                'Dans un bol, mélanger le vinaigre avec le sel, l’ail, le piment et l’origan. Laisser reposer 5 min.',
                'Ajouter les herbes hachées et le poivre, puis l’huile d’olive en remuant.',
                'Laisser reposer au moins 1 h avant le service pour que les saveurs se développent. Se conserve 3 jours au froid, filmée au contact.',
                'Servir à température ambiante, en accompagnement des viandes grillées.',
            ],
        ],
        [
            'produit' => 'Sauce cocktail',
            'categorie' => 'Sauces',
            'description' => 'Mayonnaise maison au ketchup, citron vert et piment',
            'lignes' => [
                'Œufs' => 0.1,
                'Moutarde' => 0.001,
                'Huile végétale' => 0.02,
                'Ketchup' => 0.006,
                'Citron vert' => 0.003,
                'Piment frais' => 0.001,
                'Paprika' => 0.001,
            ],
            'etapes' => [
                'Sortir l’œuf et la moutarde 30 min à l’avance pour que tout soit à la même température.',
                'Fouetter le jaune avec la moutarde et une pincée de sel. Monter en versant l’huile en filet fin sans cesser de fouetter, jusqu’à obtenir une mayonnaise ferme.',
                'Incorporer le ketchup, le jus de citron vert, le piment haché très finement et le paprika.',
                'Rectifier l’assaisonnement. La sauce doit être rose, onctueuse et légèrement relevée.',
                'Filmer au contact et réserver au froid. Préparée avec un jaune cru, la sauce se conserve 24 h maximum entre 0 et 3 °C.',
            ],
        ],
        [
            'produit' => 'Sauce du Chef',
            'categorie' => 'Sauces',
            'description' => 'Sauce crémeuse au poivre noir du moulin',
            'lignes' => [
                'Poivre noir' => 0.002,
                'Échalote' => 0.006,
                'Beurre doux' => 0.004,
                'Crème liquide entière' => 0.04,
                'Cube d\'assaisonnement' => 0.1,
                'Moutarde' => 0.001,
            ],
            'etapes' => [
                'Concasser le poivre au moulin réglé gros. Ciseler finement les échalotes.',
                'Dans une casserole, faire fondre la moitié du beurre et suer les échalotes 2 min sans coloration.',
                'Ajouter le poivre concassé et le torréfier 1 min en remuant pour libérer ses arômes.',
                'Mouiller avec l’eau et le cube émietté. Réduire de moitié.',
                'Ajouter la crème et la moutarde, laisser réduire à feu doux 8 à 10 min jusqu’à consistance nappante.',
                'Hors du feu, monter avec le reste du beurre froid en parcelles. Rectifier le sel. Maintenir au chaud au bain-marie, sans faire bouillir.',
            ],
        ],
        [
            'produit' => 'Sauce pimentée maison',
            'categorie' => 'Sauces',
            'description' => 'Piment frais confit à l’huile',
            'lignes' => [
                'Piment frais' => 0.015,
                'Oignon' => 0.007,
                'Ail (gousse)' => 0.3,
                'Gingembre frais' => 0.002,
                'Huile végétale' => 0.01,
                'Cube d\'assaisonnement' => 0.1,
                'Citron vert' => 0.005,
            ],
            'etapes' => [
                'Porter des gants. Retirer les queues des piments. Pour une sauce moins forte, épépiner une partie des piments.',
                'Mixer grossièrement les piments, l’oignon, l’ail et le gingembre : la sauce doit garder un peu de texture.',
                'Chauffer l’huile à feu moyen, ajouter le mélange et le cube émietté. Cuire 15 min à feu doux en remuant, jusqu’à ce que l’eau soit évaporée et que l’huile remonte en surface.',
                'Hors du feu, ajouter le jus de citron vert et rectifier le sel.',
                'Laisser refroidir et mettre en pot couvert d’une fine couche d’huile. Se conserve 10 jours au froid.',
            ],
        ],
        [
            'produit' => 'Sauce tartare',
            'categorie' => 'Sauces',
            'description' => 'Sauce maison montée, cornichons, câpres et cébette',
            'lignes' => [
                'Œufs' => 0.2,
                'Moutarde' => 0.002,
                'Huile végétale' => 0.03,
                'Vinaigre' => 0.002,
                'Cornichons' => 0.008,
                'Câpres' => 0.003,
                'Cébette (botte)' => 0.05,
                'Persil (botte)' => 0.025,
                'Citron vert' => 0.005,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Sortir les œufs et la moutarde 30 min à l’avance : tous les ingrédients doivent être à la même température pour que la sauce monte.',
                'Fouetter les jaunes avec la moutarde, le vinaigre, une pincée de sel et le poivre.',
                'Verser l’huile en filet fin en fouettant sans arrêt, jusqu’à obtenir une mayonnaise ferme.',
                'Hacher finement les cornichons et les câpres égouttés, ciseler la cébette et le persil.',
                'Incorporer la garniture et le jus de citron vert à la mayonnaise. Rectifier l’assaisonnement.',
                'Filmer au contact et réserver au froid. Préparée avec des jaunes crus, la sauce se conserve 24 h maximum entre 0 et 3 °C.',
            ],
        ],
        [
            'produit' => 'Sauce tomate au gingembre',
            'categorie' => 'Sauces',
            'description' => 'Sauce tomate mijotée, gingembre frais',
            'lignes' => [
                'Tomate' => 0.08,
                'Oignon' => 0.015,
                'Ail (gousse)' => 0.3,
                'Gingembre frais' => 0.004,
                'Concentré de tomate' => 0.003,
                'Huile végétale' => 0.004,
                'Cube d\'assaisonnement' => 0.1,
                'Sucre' => 0.001,
                'Piment frais' => 0.001,
                'Thym séché' => 0.001,
                'Laurier (feuille)' => 0.1,
                'Poivre noir' => 0.001,
            ],
            'etapes' => [
                'Monder les tomates, les épépiner et les concasser. Ciseler l’oignon, hacher l’ail et le piment, râper le gingembre.',
                'Faire suer l’oignon dans l’huile 5 min sans coloration. Ajouter l’ail et les deux tiers du gingembre, cuire 1 min.',
                'Ajouter le concentré de tomate et le faire revenir 2 min pour retirer son acidité.',
                'Ajouter les tomates, le cube émietté, le sucre, le piment, le thym et le laurier. Mijoter 30 min à feu doux à découvert, jusqu’à consistance nappante.',
                'Retirer le laurier, mixer selon la texture souhaitée. Ajouter le reste du gingembre râpé en fin de cuisson pour garder sa fraîcheur. Rectifier le sel et le poivre.',
                'Se conserve 4 jours au froid. Servir chaud ou tiède.',
            ],
        ],
        [
            'produit' => 'Sauce verte',
            'categorie' => 'Sauces',
            'description' => 'Sauce au choix des planches',
            'lignes' => [
                'Ail (gousse)' => 0.064,
                'Persil (botte)' => 0.078,
                'Poivron' => 0.003,
                'Cébette (botte)' => 0.031,
                'Mayonnaise' => 0.006,
                'Crème épaisse' => 0.003,
                'Arôme Maggi' => 0.002,
            ],
            'etapes' => [
                'Mixer l\'ail, le persil, le poivron vert, la cébette et 200 g de mayonnaise.',
                'Incorporer le reste de la mayonnaise et la crème épaisse.',
                'Assaisonner avec le sel, le poivre et l\'arôme Maggi.',
                'Réserver au froid 6h minimum avant le service.',
            ],
        ],
        [
            'produit' => 'Moelleux au chocolat',
            'categorie' => 'Desserts',
            'description' => 'Cœur coulant, glace au lait',
            'lignes' => [
                'Chocolat noir de couverture' => 0.025,
                'Beurre doux' => 0.022,
                'Sucre' => 0.015,
                'Œufs' => 0.5,
                'Farine de blé' => 0.006,
                'Glace au lait' => 0.06,
            ],
            'etapes' => [
                'Beurrer et fariner 10 cercles ou moules individuels de 7 cm.',
                'Faire fondre le chocolat et le beurre au bain-marie, à 45 °C maximum.',
                'Fouetter les œufs et le sucre sans les faire mousser, ajouter le chocolat fondu, puis la farine tamisée. Mélanger juste ce qu’il faut.',
                'Garnir les moules aux trois quarts et réserver au froid au moins 1 h. L’appareil se garde 24 h.',
                'Cuire à la commande 9 à 10 min à 200 °C : les bords doivent être cuits et le centre encore tremblotant.',
                'Démouler aussitôt sur assiette et servir avec une boule de glace au lait.',
            ],
        ],
        [
            'produit' => 'Profiteroles au craquelin',
            'categorie' => 'Desserts',
            'description' => 'Sauce chocolat Ivoire, glace vanille',
            'lignes' => [
                'Lait' => 0.013,
                'Beurre doux' => 0.016,
                'Sucre' => 0.007,
                'Farine de blé' => 0.021,
                'Œufs' => 0.5,
                'Chocolat blanc de couverture' => 0.02,
                'Crème liquide entière' => 0.02,
                'Glace vanille' => 0.09,
            ],
            'etapes' => [
                'Pour le craquelin, mélanger le beurre pommade, le sucre et la farine. Étaler finement entre deux feuilles, congeler, puis détailler 30 disques de 4 cm.',
                'Pour la pâte à choux, porter à ébullition l’eau, le lait, le beurre, le sucre et le sel. Ajouter la farine d’un coup et dessécher 1 min sur le feu. Hors du feu, incorporer les œufs un à un jusqu’à obtenir une pâte lisse qui forme un ruban.',
                'Pocher 30 choux de 4 cm, poser un disque de craquelin sur chacun. Cuire 25 à 30 min à 170 °C sans ouvrir le four, jusqu’à ce qu’ils soient bien dorés et secs. Refroidir sur grille.',
                'Pour la sauce, porter la crème à ébullition, la verser en trois fois sur le chocolat blanc haché en mélangeant au centre. Mixer pour lisser et garder tiède.',
                'À la commande, ouvrir les choux aux deux tiers, garnir chacun d’une petite boule de glace vanille et refermer.',
                'Dresser 3 profiteroles par assiette et napper de sauce chocolat Ivoire chaude devant le client.',
            ],
        ],
        [
            'produit' => 'Riz au lait vanillé et son caramel beurre salé',
            'categorie' => 'Desserts',
            'description' => 'Riz au lait crémeux à la gousse de vanille',
            'lignes' => [
                'Riz rond' => 0.02,
                'Lait' => 0.12,
                'Sucre' => 0.027,
                'Gousse de vanille' => 0.2,
                'Crème liquide entière' => 0.03,
                'Beurre doux' => 0.006,
                'Fleur de sel' => 0.001,
            ],
            'etapes' => [
                'Blanchir le riz 2 min à l’eau bouillante, égoutter.',
                'Porter le lait à ébullition avec les gousses de vanille fendues et grattées, le sucre et le sel. Ajouter le riz et cuire 35 à 40 min à feu très doux en remuant régulièrement, jusqu’à ce que le riz soit fondant et le mélange crémeux.',
                'Retirer les gousses, laisser refroidir. Monter la crème liquide bien froide en chantilly souple et l’incorporer délicatement au riz froid.',
                'Pour le caramel, cuire le sucre à sec jusqu’à une couleur ambrée. Hors du feu, verser la crème chaude en trois fois, puis ajouter le beurre et la fleur de sel. Recuire 1 min et laisser tiédir.',
                'Dresser le riz au lait en verrine ou en assiette creuse et napper de caramel beurre salé au moment du service.',
            ],
        ],
        [
            'produit' => 'Tarte Tatin et sa glace vanille',
            'categorie' => 'Desserts',
            'description' => 'Pommes caramélisées, feuilletage, glace vanille',
            'lignes' => [
                'Pomme' => 0.2,
                'Sucre' => 0.02,
                'Beurre doux' => 0.012,
                'Gousse de vanille' => 0.1,
                'Pâte feuilletée' => 0.03,
                'Glace vanille' => 0.06,
            ],
            'etapes' => [
                'Éplucher les pommes, les couper en quatre et retirer le cœur.',
                'Dans un moule à tatin de 26 cm allant sur le feu, réaliser un caramel à sec avec le sucre. Hors du feu, ajouter le beurre en morceaux et les graines de vanille.',
                'Ranger les quartiers de pommes debout et bien serrés sur le caramel. Cuire 20 min à feu doux pour qu’elles commencent à confire.',
                'Couvrir d’un disque de pâte feuilletée piqué, en rentrant les bords à l’intérieur du moule. Cuire 40 min au four à 180 °C.',
                'Laisser tiédir 15 min, puis retourner sur un plat. Détailler en 10 parts.',
                'Servir tiède avec une boule de glace vanille à part.',
            ],
        ],
        [
            'produit' => 'Tarte au citron meringuée déstructurée',
            'categorie' => 'Desserts',
            'description' => 'Sablé breton, crémeux citron, meringue italienne',
            'lignes' => [
                'Farine de blé' => 0.015,
                'Beurre doux' => 0.025,
                'Sucre' => 0.041,
                'Œufs' => 0.7,
                'Citron jaune' => 0.03,
                'Maïzena' => 0.001,
            ],
            'etapes' => [
                'Pour le sablé, sabler la farine avec le beurre froid, le sucre et le sel, puis ajouter le jaune. Étaler sur 5 mm entre deux feuilles, réserver 30 min au froid. Cuire 15 min à 170 °C, refroidir et concasser en gros éclats.',
                'Pour le crémeux, porter à frémissement le jus et les zestes de citron avec la moitié du sucre. Fouetter les œufs avec le reste du sucre et la maïzena, verser le jus chaud dessus, remettre sur le feu et cuire 1 min après ébullition en fouettant.',
                'Laisser redescendre à 45 °C, puis incorporer le beurre en morceaux en mixant. Filmer au contact et réserver 4 h au froid. Mettre en poche à douille unie.',
                'Pour la meringue, cuire le sucre et l’eau à 118 °C. Monter les blancs en neige et verser le sucre cuit en filet, puis fouetter jusqu’à complet refroidissement. Mettre en poche à douille cannelée.',
                'Au dressage, répartir des éclats de sablé dans l’assiette, pocher des points de crémeux citron et des pointes de meringue. Colorer la meringue au chalumeau et finir de quelques zestes de citron.',
            ],
        ],
    ];

    public function up(): void
    {
        // Données réelles d'exploitation : la base de test, recréée à chaque
        // test, n'en veut pas. Le test de l'import appelle importer() directement.
        if (app()->environment('testing')) {
            return;
        }

        $this->importer();
    }

    /**
     * Rien à défaire automatiquement : une fois importées, les fiches sont
     * reprises et ventes et cartes s'y rattachent.
     */
    public function down(): void {}

    public function importer(): void
    {
        DB::transaction(function (): void {
            $unites = $this->unites();
            $matieres = $this->matieres($unites);
            $sousRecettes = $this->sousRecettes($matieres, $unites);
            $categories = $this->categoriesProduit();

            foreach (self::PLANCHES as $libelle => $description) {
                $this->produit($libelle, $categories['Plats'], $description, estMenu: true);
            }

            foreach (self::FICHES as $fiche) {
                $this->fiche($fiche, $categories, $matieres, $sousRecettes);
            }
        });
    }

    /**
     * @return array<string, int> id par symbole
     */
    private function unites(): array
    {
        $libelles = ['kg' => 'Kilogramme', 'L' => 'Litre', 'pce' => 'Pièce'];

        foreach ($libelles as $symbole => $libelle) {
            if (! DB::table('unites_de_mesure')->where('symbole', $symbole)->exists()) {
                DB::table('unites_de_mesure')->insert([
                    'libelle' => $libelle,
                    'symbole' => $symbole,
                    'statut' => 'actif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return DB::table('unites_de_mesure')->whereIn('symbole', array_keys($libelles))->pluck('id', 'symbole')->all();
    }

    /**
     * Reprend une matière existante par libellé, sinon la crée.
     *
     * Une matière existante stockée dans une autre unité fausserait les
     * quantités (0,2 « L » compté comme 0,2 pièce) : on refuse plutôt que
     * d'importer des fiches fausses.
     *
     * @param  array<string, int>  $unites
     * @return array<string, array{id: int, unite_id: int}>
     */
    private function matieres(array $unites): array
    {
        $resultat = [];

        foreach (self::MATIERES as $libelle => [$categorie, $symbole]) {
            $existante = DB::table('matieres_premieres')
                ->where('libelle', $libelle)
                ->whereNull('deleted_at')
                ->first(['id', 'unite_de_mesure_id']);

            if ($existante !== null && (int) $existante->unite_de_mesure_id !== $unites[$symbole]) {
                throw new RuntimeException("La matière « {$libelle} » existe déjà dans une autre unité que « {$symbole} ».");
            }

            $id = $existante?->id ?? DB::table('matieres_premieres')->insertGetId([
                'categorie_id' => $this->categorieMatiere($categorie),
                'unite_de_mesure_id' => $unites[$symbole],
                'libelle' => $libelle,
                'seuil_alerte_stock' => 0,
                'gestion_peremption' => false,
                'statut' => 'actif',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $resultat[$libelle] = ['id' => (int) $id, 'unite_id' => $unites[$symbole]];
        }

        return $resultat;
    }

    private function categorieMatiere(string $libelle): int
    {
        return (int) (DB::table('categories_matiere_premiere')->where('libelle', $libelle)->value('id')
            ?? DB::table('categories_matiere_premiere')->insertGetId([
                'libelle' => $libelle,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    }

    /**
     * @param  array<string, array{id: int, unite_id: int}>  $matieres
     * @param  array<string, int>  $unites
     * @return array<string, int> id par libellé
     */
    private function sousRecettes(array $matieres, array $unites): array
    {
        $resultat = [];

        foreach (self::SOUS_RECETTES as $libelle => $sousRecette) {
            $id = DB::table('sous_recettes')->where('libelle', $libelle)->value('id');

            if ($id === null) {
                $id = DB::table('sous_recettes')->insertGetId([
                    'libelle' => $libelle,
                    'quantite_produite' => $sousRecette['quantite_produite'],
                    'unite_id' => $unites['kg'],
                    'cout_matiere' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($sousRecette['lignes'] as $matiere => $quantite) {
                    DB::table('lignes_sous_recette')->insert([
                        'sous_recette_id' => $id,
                        'matiere_premiere_id' => $matieres[$matiere]['id'],
                        'unite_id' => $matieres[$matiere]['unite_id'],
                        'quantite' => $quantite,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                SousRecette::query()->findOrFail($id)->recalculerCoutMatiere();
            }

            $resultat[$libelle] = (int) $id;
        }

        return $resultat;
    }

    /**
     * Catégories de la carte utilisées par l'import ; Accompagnements et
     * Sauces sont créées après les sections existantes si elles manquent.
     *
     * @return array<string, int> id par libellé
     */
    private function categoriesProduit(): array
    {
        $resultat = [];

        foreach (['Entrées', 'Plats', 'Accompagnements', 'Sauces', 'Desserts'] as $libelle) {
            $resultat[$libelle] = (int) (DB::table('categories_produit')->where('libelle', $libelle)->value('id')
                ?? DB::table('categories_produit')->insertGetId([
                    'libelle' => $libelle,
                    'ordre' => (int) DB::table('categories_produit')->max('ordre') + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
        }

        return $resultat;
    }

    private function produit(string $libelle, int $categorieId, ?string $description, bool $estMenu = false): int
    {
        $id = DB::table('produits')->where('libelle', $libelle)->whereNull('deleted_at')->value('id');

        return (int) ($id ?? DB::table('produits')->insertGetId([
            'categorie_id' => $categorieId,
            'libelle' => $libelle,
            'description' => $description,
            'est_menu' => $estMenu,
            'statut' => 'actif',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * @param  array{produit: string, categorie: string, description: ?string, lignes: array<string, float|int>, sous_recettes?: array<string, float>, etapes: array<int, string>}  $fiche
     * @param  array<string, int>  $categories
     * @param  array<string, array{id: int, unite_id: int}>  $matieres
     * @param  array<string, int>  $sousRecettes
     */
    private function fiche(array $fiche, array $categories, array $matieres, array $sousRecettes): void
    {
        $produitId = $this->produit($fiche['produit'], $categories[$fiche['categorie']], $fiche['description']);

        $fiches = DB::table('fiches_techniques')->where('produit_id', $produitId);

        if ((clone $fiches)->where('statut', 'active')->exists()) {
            return;
        }

        $ficheId = DB::table('fiches_techniques')->insertGetId([
            'produit_id' => $produitId,
            'version' => (int) $fiches->max('version') + 1,
            'cout_matiere' => 0,
            'statut' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($fiche['lignes'] as $matiere => $quantite) {
            DB::table('lignes_fiche_technique')->insert([
                'fiche_technique_id' => $ficheId,
                'matiere_premiere_id' => $matieres[$matiere]['id'],
                'unite_id' => $matieres[$matiere]['unite_id'],
                'quantite' => $quantite,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($fiche['sous_recettes'] ?? [] as $sousRecette => $quantite) {
            DB::table('lignes_fiche_technique_sous_recette')->insert([
                'fiche_technique_id' => $ficheId,
                'sous_recette_id' => $sousRecettes[$sousRecette],
                'quantite' => $quantite,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($fiche['etapes'] as $rang => $description) {
            DB::table('etapes_fiche_technique')->insert([
                'fiche_technique_id' => $ficheId,
                'ordre' => $rang + 1,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(FicheTechniqueService::class)->recalculerCoutMatiere(FicheTechnique::query()->findOrFail($ficheId));
    }
};
