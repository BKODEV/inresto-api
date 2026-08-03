<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Statut;
use App\Models\Budget;
use App\Models\CatalogueFournisseur;
use App\Models\CategorieMatierePremiere;
use App\Models\CategorieProduit;
use App\Models\FicheTechnique;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\Produit;
use App\Models\Reception;
use App\Models\Site;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\CommandeFournisseurService;
use App\Services\FicheTechniqueService;
use App\Services\ReceptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Peuple une carte de restaurant international : produits, matières premières
 * manquantes, prix catalogue et fiches techniques.
 *
 * Contrairement à `inresto:demo` qui regénère un jeu complet avec des factories,
 * cette commande est **idempotente et additive** : elle ne crée que ce qui
 * manque et ne touche à rien d'autre. On peut donc la lancer sur une base déjà
 * exploitée sans rien perdre.
 */
class SeedDemoCarte extends Command
{
    protected $signature = 'inresto:demo-carte
                            {--photos= : Dossier contenant les photos, nommées « <Libellé du plat>.jpg »}
                            {--sans-stock : Ne pas générer les commandes fournisseur d\'approvisionnement}';

    protected $description = 'Crée une carte internationale (produits, matières, fiches techniques, stock) sans écraser l\'existant';

    /** Marqueur porté par les commandes générées ici : sert de test d'idempotence. */
    private const MARQUEUR = 'Approvisionnement initial — inresto:demo-carte';

    /** Quantité commandée puis reçue, par matière et par site (dans l'unité de la matière). */
    private const QUANTITE_APPRO = 10;

    /**
     * Fournisseurs spécialisés : un par catégorie de matière première. Commander
     * la crèmerie chez le boucher n'aurait aucun sens à l'usage, et un catalogue
     * par métier rend les écrans de commande lisibles.
     */
    private const FOURNISSEURS = [
        'Fruits & Légumes' => 'Primeur Abidjan Frais',
        'Viandes & Volailles' => 'Boucherie du Plateau',
        'Produits de la mer' => 'Marée Atlantique CI',
        'Épicerie' => 'Épicerie Générale Cocody',
        'Crèmerie' => 'Crèmerie Laitière Ivoire',
    ];

    /**
     * Matières premières à garantir : libellé => [catégorie, unité, prix d'achat FCFA].
     * Le prix alimente le catalogue fournisseur, seule source du coût matière
     * d'une fiche technique — sans lui les fiches sortiraient à 0.
     */
    private const MATIERES = [
        // Fruits & légumes
        'Avocat' => ['Fruits & Légumes', 'kg', 1500],
        'Citron' => ['Fruits & Légumes', 'kg', 1200],
        'Concombre' => ['Fruits & Légumes', 'kg', 900],
        'Poivron' => ['Fruits & Légumes', 'kg', 1800],
        'Champignons de Paris' => ['Fruits & Légumes', 'kg', 3500],
        'Ail' => ['Fruits & Légumes', 'kg', 2500],
        'Basilic' => ['Fruits & Légumes', 'kg', 4000],
        'Persil' => ['Fruits & Légumes', 'kg', 3000],
        'Carotte' => ['Fruits & Légumes', 'kg', 800],
        'Courgette' => ['Fruits & Légumes', 'kg', 1200],
        'Potiron' => ['Fruits & Légumes', 'kg', 700],
        'Pomme' => ['Fruits & Légumes', 'kg', 2000],
        'Ananas' => ['Fruits & Légumes', 'kg', 800],
        'Mangue' => ['Fruits & Légumes', 'kg', 1000],
        'Banane dessert' => ['Fruits & Légumes', 'kg', 700],
        'Olives noires' => ['Fruits & Légumes', 'kg', 4500],
        'Gingembre' => ['Fruits & Légumes', 'kg', 2200],
        // Viandes & volailles
        'Merguez' => ['Viandes & Volailles', 'kg', 5500],
        'Échine de porc' => ['Viandes & Volailles', 'kg', 4200],
        'Lardons' => ['Viandes & Volailles', 'kg', 5000],
        // Produits de la mer
        'Saumon' => ['Produits de la mer', 'kg', 12000],
        'Bar' => ['Produits de la mer', 'kg', 7000],
        'Cabillaud' => ['Produits de la mer', 'kg', 8500],
        'Morue salée' => ['Produits de la mer', 'kg', 9000],
        'Moules' => ['Produits de la mer', 'kg', 4000],
        'Calamars' => ['Produits de la mer', 'kg', 6500],
        'Thon' => ['Produits de la mer', 'kg', 5000],
        // Épicerie
        'Spaghetti' => ['Épicerie', 'kg', 1500],
        'Riz arborio' => ['Épicerie', 'kg', 2800],
        'Semoule de couscous' => ['Épicerie', 'kg', 1400],
        'Levure boulangère' => ['Épicerie', 'kg', 6000],
        'Chapelure' => ['Épicerie', 'kg', 1800],
        'Chocolat noir' => ['Épicerie', 'kg', 7500],
        'Cacao en poudre' => ['Épicerie', 'kg', 6500],
        'Café moulu' => ['Épicerie', 'kg', 8000],
        'Biscuits cuillère' => ['Épicerie', 'kg', 5500],
        'Vinaigre balsamique' => ['Épicerie', 'L', 4500],
        'Sauce soja' => ['Épicerie', 'L', 3500],
        'Curry en poudre' => ['Épicerie', 'kg', 7000],
        'Pain baguette' => ['Épicerie', 'pce', 250],
        'Pain de mie' => ['Épicerie', 'kg', 2000],
        'Attiéké' => ['Épicerie', 'kg', 900],
        'Pâte d\'arachide' => ['Épicerie', 'kg', 3000],
        'Feuille de nori' => ['Épicerie', 'pce', 300],
        // Crèmerie
        'Beurre' => ['Crèmerie', 'kg', 6500],
        'Crème fraîche' => ['Crèmerie', 'L', 4000],
        'Mascarpone' => ['Crèmerie', 'kg', 7000],
        'Feta' => ['Crèmerie', 'kg', 6000],
        'Mozzarella' => ['Crèmerie', 'kg', 6500],
        'Parmesan' => ['Crèmerie', 'kg', 12000],
    ];

    /**
     * La carte : libellé => [catégorie produit, [matière => quantité par portion]].
     * Les quantités sont exprimées dans l'unité de la matière (kg, L, pce).
     */
    private const CARTE = [
        // ── Entrées ─────────────────────────────────────────────────────────
        'Salade César' => ['Entrées', ['Salade verte' => 0.150, 'Poulet' => 0.080, 'Parmesan' => 0.020, 'Pain de mie' => 0.040, 'Œufs' => 1]],
        'Salade grecque' => ['Entrées', ['Tomate' => 0.150, 'Concombre' => 0.100, 'Feta' => 0.060, 'Olives noires' => 0.030, 'Oignon' => 0.030]],
        'Bruschetta' => ['Entrées', ['Pain baguette' => 0.5, 'Tomate' => 0.120, 'Basilic' => 0.005, 'Ail' => 0.003, 'Huile végétale' => 0.015]],
        'Soupe à l\'oignon' => ['Entrées', ['Oignon' => 0.250, 'Beurre' => 0.020, 'Fromage' => 0.040, 'Pain baguette' => 0.3]],
        'Velouté de potiron' => ['Entrées', ['Potiron' => 0.300, 'Crème fraîche' => 0.030, 'Oignon' => 0.040, 'Sel' => 0.002]],
        'Avocat aux crevettes' => ['Entrées', ['Avocat' => 0.180, 'Crevettes' => 0.080, 'Citron' => 0.020, 'Salade verte' => 0.050]],
        'Accras de morue' => ['Entrées', ['Morue salée' => 0.100, 'Farine de blé' => 0.080, 'Huile végétale' => 0.060, 'Persil' => 0.005]],
        // ── Plats internationaux ────────────────────────────────────────────
        'Steak frites' => ['Plats', ['Bœuf' => 0.220, 'Pomme de terre' => 0.250, 'Beurre' => 0.020, 'Huile végétale' => 0.030]],
        'Spaghetti bolognaise' => ['Plats', ['Spaghetti' => 0.120, 'Bœuf' => 0.120, 'Tomate' => 0.150, 'Oignon' => 0.040, 'Parmesan' => 0.015]],
        'Pizza margherita' => ['Plats', ['Farine de blé' => 0.200, 'Levure boulangère' => 0.005, 'Tomate' => 0.120, 'Mozzarella' => 0.100, 'Basilic' => 0.005]],
        'Burger frites' => ['Plats', ['Bœuf' => 0.150, 'Pain de mie' => 0.090, 'Fromage' => 0.030, 'Salade verte' => 0.030, 'Pomme de terre' => 0.200]],
        'Fish and chips' => ['Plats', ['Cabillaud' => 0.180, 'Farine de blé' => 0.060, 'Pomme de terre' => 0.250, 'Huile végétale' => 0.080]],
        'Saumon grillé' => ['Plats', ['Saumon' => 0.180, 'Citron' => 0.020, 'Courgette' => 0.100, 'Beurre' => 0.015]],
        'Poisson grillé' => ['Plats', ['Bar' => 0.250, 'Citron' => 0.020, 'Huile végétale' => 0.020, 'Sel' => 0.003]],
        'Plateau de fruits de mer' => ['Plats', ['Crevettes' => 0.120, 'Moules' => 0.150, 'Calamars' => 0.100, 'Saumon' => 0.100, 'Citron' => 0.030]],
        'Sushi assortiment' => ['Plats', ['Riz arborio' => 0.150, 'Saumon' => 0.080, 'Thon' => 0.060, 'Feuille de nori' => 3, 'Sauce soja' => 0.020]],
        'Risotto aux champignons' => ['Plats', ['Riz arborio' => 0.140, 'Champignons de Paris' => 0.120, 'Parmesan' => 0.030, 'Beurre' => 0.025, 'Oignon' => 0.030]],
        'Curry de poulet' => ['Plats', ['Poulet' => 0.200, 'Curry en poudre' => 0.008, 'Crème fraîche' => 0.050, 'Riz' => 0.120, 'Oignon' => 0.040]],
        'Couscous royal' => ['Plats', ['Semoule de couscous' => 0.150, 'Poulet' => 0.120, 'Merguez' => 0.100, 'Carotte' => 0.080, 'Courgette' => 0.080]],
        'Poulet rôti' => ['Plats', ['Poulet' => 0.300, 'Pomme de terre' => 0.200, 'Ail' => 0.005, 'Beurre' => 0.020]],
        'Brochettes grillées' => ['Plats', ['Échine de porc' => 0.220, 'Poivron' => 0.060, 'Oignon' => 0.040, 'Pomme de terre' => 0.180]],
        'Lasagnes' => ['Plats', ['Farine de blé' => 0.120, 'Bœuf' => 0.130, 'Tomate' => 0.150, 'Lait' => 0.100, 'Mozzarella' => 0.080]],
        // ── Plats africains ─────────────────────────────────────────────────
        'Attiéké poisson' => ['Plats', ['Attiéké' => 0.250, 'Tilapia' => 0.250, 'Tomate' => 0.080, 'Oignon' => 0.050]],
        'Garba' => ['Plats', ['Attiéké' => 0.250, 'Thon' => 0.150, 'Huile végétale' => 0.060, 'Oignon' => 0.040]],
        'Alloco' => ['Plats', ['Banane plantain' => 0.300, 'Huile végétale' => 0.080, 'Sel' => 0.002]],
        'Kedjenou de poulet' => ['Plats', ['Poulet' => 0.300, 'Tomate' => 0.120, 'Oignon' => 0.060, 'Gingembre' => 0.010]],
        'Riz gras' => ['Plats', ['Riz' => 0.180, 'Tomate' => 0.100, 'Poulet' => 0.120, 'Huile végétale' => 0.040, 'Oignon' => 0.050]],
        // ── Desserts ────────────────────────────────────────────────────────
        'Tiramisu' => ['Desserts', ['Mascarpone' => 0.080, 'Biscuits cuillère' => 0.040, 'Café moulu' => 0.010, 'Œufs' => 1, 'Cacao en poudre' => 0.005]],
        'Crème brûlée' => ['Desserts', ['Crème fraîche' => 0.120, 'Œufs' => 2, 'Sucre' => 0.040]],
        'Fondant au chocolat' => ['Desserts', ['Chocolat noir' => 0.060, 'Beurre' => 0.050, 'Œufs' => 2, 'Farine de blé' => 0.020, 'Sucre' => 0.040]],
        'Mousse au chocolat' => ['Desserts', ['Chocolat noir' => 0.070, 'Œufs' => 2, 'Sucre' => 0.020, 'Crème fraîche' => 0.040]],
        'Profiteroles' => ['Desserts', ['Farine de blé' => 0.050, 'Beurre' => 0.030, 'Œufs' => 2, 'Chocolat noir' => 0.040, 'Lait' => 0.080]],
        'Tarte au citron meringuée' => ['Desserts', ['Farine de blé' => 0.080, 'Citron' => 0.100, 'Œufs' => 2, 'Sucre' => 0.070, 'Beurre' => 0.040]],
        'Salade de fruits' => ['Desserts', ['Ananas' => 0.080, 'Mangue' => 0.080, 'Banane dessert' => 0.060, 'Pomme' => 0.060, 'Sucre' => 0.010]],
    ];

    public function handle(FicheTechniqueService $fiches): int
    {
        $userId = User::query()->value('id');

        if ($userId === null) {
            $this->components->error('Aucun utilisateur en base : lancez `php artisan inresto:install` d\'abord.');

            return self::FAILURE;
        }

        $categoriesProduit = CategorieProduit::all()->keyBy('libelle');
        $categoriesMatiere = CategorieMatierePremiere::all()->keyBy('libelle');
        $unites = UniteDeMesure::all()->keyBy('symbole');

        if ($categoriesProduit->isEmpty() || $categoriesMatiere->isEmpty() || $unites->isEmpty()) {
            $this->components->error('Référentiels manquants (catégories produit/matière ou unités). Lancez les seeders.');

            return self::FAILURE;
        }

        $fournisseurs = $this->garantirFournisseurs();
        $matieres = $this->garantirMatieres($fournisseurs, $categoriesMatiere, $unites);
        $this->garantirProduitsEtFiches($fiches, $matieres, $categoriesProduit, $userId);

        if (! $this->option('sans-stock')) {
            $this->approvisionnerStock($fournisseurs, $userId);
        }

        return self::SUCCESS;
    }

    /**
     * Un fournisseur par catégorie de matière, créé s'il manque.
     *
     * @return Collection<string, Fournisseur>
     */
    private function garantirFournisseurs(): Collection
    {
        $crees = 0;
        $fournisseurs = collect(self::FOURNISSEURS)->map(function (string $nom) use (&$crees): Fournisseur {
            $fournisseur = Fournisseur::query()->firstOrCreate(
                ['nom' => $nom],
                ['statut' => Statut::Actif->value],
            );
            $crees += $fournisseur->wasRecentlyCreated ? 1 : 0;

            return $fournisseur;
        });

        $this->components->info("Fournisseurs : {$crees} créé(s) sur ".count(self::FOURNISSEURS).'.');

        return $fournisseurs;
    }

    /**
     * @param  Collection<string, Fournisseur>  $fournisseurs
     * @param  Collection<string, CategorieMatierePremiere>  $categories
     * @param  Collection<string, UniteDeMesure>  $unites
     * @return Collection<string, MatierePremiere>
     */
    private function garantirMatieres($fournisseurs, $categories, $unites)
    {
        $creees = 0;

        foreach (self::MATIERES as $libelle => [$categorie, $symbole, $prix]) {
            $fournisseur = $fournisseurs[$categorie];
            $matiere = MatierePremiere::query()->where('libelle', $libelle)->first();

            if ($matiere === null) {
                $matiere = MatierePremiere::create([
                    'libelle' => $libelle,
                    'categorie_id' => $categories[$categorie]->id,
                    'unite_de_mesure_id' => $unites[$symbole]->id,
                    'seuil_alerte_stock' => 0,
                    'gestion_peremption' => false,
                ]);
                $creees++;
            }

            // Sans prix catalogue, le coût matière de la fiche resterait à zéro.
            CatalogueFournisseur::query()->firstOrCreate(
                ['fournisseur_id' => $fournisseur->id, 'matiere_premiere_id' => $matiere->id],
                ['prix_unitaire' => $prix, 'unite_achat_id' => $matiere->unite_de_mesure_id, 'qte_par_unite_achat' => 1],
            );
        }

        $this->components->info("Matières premières : {$creees} créée(s), ".count(self::MATIERES).' au catalogue.');

        return MatierePremiere::all()->keyBy('libelle');
    }

    /**
     * @param  Collection<string, MatierePremiere>  $matieres
     * @param  Collection<string, CategorieProduit>  $categories
     */
    private function garantirProduitsEtFiches(FicheTechniqueService $service, $matieres, $categories, string $userId): void
    {
        $dossierPhotos = $this->option('photos');
        $produitsCrees = $fichesCreees = $photos = 0;
        $ignorees = [];

        foreach (self::CARTE as $libelle => [$categorie, $composition]) {
            $produit = Produit::query()->firstOrCreate(
                ['libelle' => $libelle],
                ['categorie_id' => $categories[$categorie]->id, 'est_menu' => false],
            );

            if ($produit->wasRecentlyCreated) {
                $produitsCrees++;
            }

            if ($dossierPhotos !== null && $this->attacherPhoto($produit, $dossierPhotos)) {
                $photos++;
            }

            // Une fiche existante n'est jamais écrasée : elle porte peut-être un
            // travail de l'utilisateur, et la modifier créerait une version.
            $dejaUneFiche = FicheTechnique::query()->where('produit_id', $produit->id)->exists();

            if ($dejaUneFiche) {
                continue;
            }

            $lignes = [];
            foreach ($composition as $nomMatiere => $quantite) {
                if (! isset($matieres[$nomMatiere])) {
                    $ignorees[] = "{$libelle} → {$nomMatiere}";

                    continue;
                }
                $matiere = $matieres[$nomMatiere];
                $lignes[] = [
                    'matiere_premiere_id' => $matiere->id,
                    'unite_id' => $matiere->unite_de_mesure_id,
                    'quantite' => $quantite,
                ];
            }

            $service->creer([
                'produit_id' => $produit->id,
                'lignes_matieres' => $lignes,
                'lignes_sous_recettes' => [],
            ], $userId);
            $fichesCreees++;
        }

        $this->components->info("Produits : {$produitsCrees} créé(s) sur ".count(self::CARTE).'.');
        $this->components->info("Fiches techniques : {$fichesCreees} créée(s).");

        if ($dossierPhotos !== null) {
            $this->components->info("Photos attachées : {$photos}.");
        }

        if ($ignorees !== []) {
            $this->components->warn('Matières introuvables, lignes omises : '.implode(', ', $ignorees));
        }
    }

    /**
     * Fait entrer du stock **par le circuit métier réel** : une commande
     * fournisseur envoyée, puis une réception confirmée par site.
     *
     * On ne crée pas les lignes de `stocks` directement, alors que ce serait plus
     * court : passer par `ReceptionService` produit aussi les mouvements de
     * stock, le PMP et la dépense budgétaire. Un jeu d'essai qui saute ces
     * étapes donne des écrans de reporting vides et un food cost faux.
     *
     * @param  Collection<string, Fournisseur>  $fournisseurs
     */
    private function approvisionnerStock($fournisseurs, string $userId): void
    {
        $sites = Site::query()->where('statut', Statut::Actif->value)->get();

        if ($sites->isEmpty()) {
            $this->components->warn('Aucun site actif : approvisionnement ignoré.');

            return;
        }

        $commandes = app(CommandeFournisseurService::class);
        $receptions = app(ReceptionService::class);
        $creees = 0;
        $ignorees = 0;

        foreach ($sites as $site) {
            $this->garantirBudgetDuMois($site);

            foreach ($fournisseurs as $fournisseur) {
                if ($this->dejaApprovisionne($site, $fournisseur)) {
                    $ignorees++;

                    continue;
                }

                $catalogue = CatalogueFournisseur::query()
                    ->where('fournisseur_id', $fournisseur->id)
                    ->get();

                if ($catalogue->isEmpty()) {
                    continue;
                }

                $lignes = $catalogue->map(fn (CatalogueFournisseur $c): array => [
                    'matiere_premiere_id' => $c->matiere_premiere_id,
                    'unite_id' => $c->unite_achat_id,
                    'quantite_commandee' => self::QUANTITE_APPRO,
                    'prix_unitaire_previsionnel' => (float) $c->prix_unitaire,
                ])->all();

                $commande = $commandes->creer(
                    ['fournisseur_id' => $fournisseur->id, 'notes' => self::MARQUEUR],
                    $lignes,
                    $userId,
                );
                $commandes->marquerEnvoyee($commande);

                $reception = new Reception([
                    'commande_fournisseur_id' => $commande->id,
                    'site_id' => $site->id,
                    'date_reception' => now()->toDateString(),
                    'observations' => self::MARQUEUR,
                ]);
                $reception->user_id = $userId;
                $reception->save();

                // La réception intégrale bascule la commande en « clôturée ».
                $receptions->confirmer(
                    $reception,
                    $catalogue->map(fn (CatalogueFournisseur $c): array => [
                        'matiere_premiere_id' => $c->matiere_premiere_id,
                        'unite_id' => $c->unite_achat_id,
                        'quantite_recue' => self::QUANTITE_APPRO,
                        'prix_unitaire_reel' => (float) $c->prix_unitaire,
                    ])->all(),
                    $userId,
                );
                $creees++;
            }
        }

        $this->components->info("Approvisionnement : {$creees} commande(s) clôturée(s), {$ignorees} déjà en place.");
    }

    /**
     * Une réception exige un budget sur le site pour son mois, sinon
     * `ReceptionService` lève `budgetSiteAbsent`. On ne touche pas à un budget
     * existant : son montant est peut-être celui que l'utilisateur teste.
     */
    private function garantirBudgetDuMois(Site $site): void
    {
        Budget::query()->firstOrCreate(
            ['site_id' => $site->id, 'mois' => now()->startOfMonth()->toDateString()],
            ['montant_alloue' => 5_000_000],
        );
    }

    /** Le marqueur porté par la commande évite de réapprovisionner à chaque exécution. */
    private function dejaApprovisionne(Site $site, Fournisseur $fournisseur): bool
    {
        return Reception::query()
            ->where('site_id', $site->id)
            ->whereHas('commande', fn ($q) => $q
                ->where('fournisseur_id', $fournisseur->id)
                ->where('notes', self::MARQUEUR))
            ->exists();
    }

    /**
     * Copie `<dossier>/<libellé>.jpg` sur le disque public et renseigne le produit.
     * Ne fait rien si le produit a déjà une photo ou si le fichier est absent.
     */
    private function attacherPhoto(Produit $produit, string $dossier): bool
    {
        if ($produit->photo !== null) {
            return false;
        }

        // Les fichiers fournis sont nommés sans accents ni apostrophes.
        $base = str_replace(['é', 'è', 'ê', 'à', 'â', 'î', 'ô', 'û', 'ç', '\''], ['e', 'e', 'e', 'a', 'a', 'i', 'o', 'u', 'c', ''], mb_strtolower($produit->libelle));

        foreach (scandir($dossier) ?: [] as $fichier) {
            if (! str_ends_with(mb_strtolower($fichier), '.jpg')) {
                continue;
            }
            $candidat = str_replace(['é', 'è', 'ê', 'à', 'â', 'î', 'ô', 'û', 'ç', '\''], ['e', 'e', 'e', 'a', 'a', 'i', 'o', 'u', 'c', ''], mb_strtolower(substr($fichier, 0, -4)));

            if ($candidat !== $base) {
                continue;
            }

            $chemin = "produits/{$produit->id}/photo.jpg";
            Storage::disk('public')->put($chemin, (string) file_get_contents($dossier.DIRECTORY_SEPARATOR.$fichier));
            $produit->photo = $chemin;
            $produit->save();

            return true;
        }

        return false;
    }
}
