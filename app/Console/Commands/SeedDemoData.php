<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CarteStatut;
use App\Enums\MouvementType;
use App\Models\Budget;
use App\Models\Caisse;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\CatalogueFournisseur;
use App\Models\CategorieMatierePremiere;
use App\Models\CategorieProduit;
use App\Models\Depense;
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\ModeDePaiement;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Reception;
use App\Models\Site;
use App\Models\SousRecette;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\CommandeFournisseurService;
use App\Services\FicheTechniqueService;
use App\Services\InventaireService;
use App\Services\ReceptionService;
use App\Services\SousRecetteService;
use App\Services\StockService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Collection;

/**
 * Remplit la base avec des données de DÉMONSTRATION via les factories.
 *
 * À distinguer des seeders (`db:seed`), réservés aux données par défaut
 * réelles (permissions, super-admin, site par défaut). Cette commande est
 * volontairement enrichie au fil des modules : ajoutez ici la génération des
 * nouvelles entités à chaque évolution du domaine.
 *
 * Usage typique en local :
 *   php artisan migrate:fresh
 *   php artisan db:seed
 *   php artisan inresto:demo
 */
#[Signature('inresto:demo {--force : Forcer l\'exécution même en production}')]
#[Description('Génère un jeu de données de démonstration via les factories (sites, paramètres, stock...).')]
class SeedDemoData extends Command
{
    use ConfirmableTrait;

    public function handle(StockService $stock): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $sites = $this->creerSites();
        $this->creerUtilisateurs($sites);
        $this->creerParametres();
        $unites = $this->creerUnites();
        $this->creerPointsDeVente($sites);

        $matieres = $this->creerMatieres();
        $fournisseurs = $this->creerFournisseursEtCatalogue($matieres, $unites);
        $this->creerBudgets($sites);
        $this->approvisionnerStock($sites, $matieres, $stock);

        // Modules Lot 2 & Lot 3 : tout passe par les services métier.
        $userId = User::query()->value('id');
        $sousRecettes = $this->creerSousRecettes($matieres, $unites, $userId);
        $produits = $this->creerProduitsEtFiches($matieres, $sousRecettes, $userId);
        $this->creerCompositionCartes($produits);
        $this->creerCommandesEtReceptions($sites, $fournisseurs, $userId);
        $this->creerChargesManuelles($sites);
        $this->creerInventaires($sites, $matieres, $userId);

        $this->components->info('Données de démonstration générées avec succès.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Site>
     */
    private function creerSites(): Collection
    {
        return Site::factory()
            ->count(2)
            ->sequence(
                ['nom' => 'Site Cocody', 'code' => 'COCODY'],
                ['nom' => 'Site Plateau', 'code' => 'PLATEAU'],
            )
            ->create();
    }

    /**
     * Une équipe d'exploitation complète par site : un utilisateur par rôle
     * opérationnel (hors super-admin), rattaché à ce site comme périmètre
     * métier. Permet de tester le RBAC site par site en démo.
     *
     * Suppose les rôles système déjà présents (lancer `inresto:install` ou
     * `db:seed` avant cette commande).
     *
     * @param  Collection<int, Site>  $sites
     */
    private function creerUtilisateurs(Collection $sites): void
    {
        $rolesOperationnels = ['gerant', 'chef-cuisine', 'econome', 'caissier', 'serveur'];

        $sites->each(function (Site $site) use ($rolesOperationnels): void {
            foreach ($rolesOperationnels as $role) {
                $user = User::factory()->create();

                $user->sites()->attach($site->id);
                $user->syncRoles([$role]);
            }
        });
    }

    private function creerParametres(): void
    {
        // Catégories produit = sections d'une carte, ordonnées (Entrées < Plats…).
        collect(['Entrées', 'Plats', 'Desserts', 'Boissons'])
            ->each(fn (string $libelle, int $i) => CategorieProduit::factory()->create([
                'libelle' => $libelle,
                'ordre' => $i + 1,
            ]));

        collect(['Fruits & Légumes', 'Viandes & Volailles', 'Produits de la mer', 'Épicerie', 'Crèmerie'])
            ->each(fn (string $libelle) => CategorieMatierePremiere::factory()->create(['libelle' => $libelle]));

        collect(['Espèces', 'Carte bancaire', 'Mobile Money', 'Virement'])
            ->each(fn (string $libelle) => ModeDePaiement::factory()->create(['libelle' => $libelle]));
    }

    /**
     * @return Collection<int, UniteDeMesure>
     */
    private function creerUnites(): Collection
    {
        return collect([
            ['libelle' => 'Kilogramme', 'symbole' => 'kg'],
            ['libelle' => 'Litre', 'symbole' => 'L'],
            ['libelle' => 'Pièce', 'symbole' => 'pce'],
            ['libelle' => 'Sac de 25 kg', 'symbole' => 'sac'],
        ])->map(fn (array $data) => UniteDeMesure::factory()->create($data));
    }

    /**
     * Deux points de vente (Lounge + Restaurant) par site, chacun avec sa
     * caisse principale et une carte.
     *
     * @param  Collection<int, Site>  $sites
     */
    private function creerPointsDeVente(Collection $sites): void
    {
        $sites->each(function (Site $site): void {
            collect([
                ['nom' => "Lounge {$site->nom}", 'type' => 'Lounge'],
                ['nom' => "Restaurant {$site->nom}", 'type' => 'Restaurant'],
            ])->each(function (array $data) use ($site): void {
                $pdv = PointDeVente::factory()->create([...$data, 'site_id' => $site->id]);

                Caisse::factory()->create([
                    'point_de_vente_id' => $pdv->id,
                    'libelle' => 'Caisse principale',
                ]);
                Carte::factory()->create(['point_de_vente_id' => $pdv->id]);
            });
        });
    }

    /**
     * Matières premières : définitions GLOBALES, partagées par tous les sites.
     *
     * @return Collection<int, MatierePremiere>
     */
    private function creerMatieres(): Collection
    {
        $categories = CategorieMatierePremiere::all()->keyBy('libelle');
        $unites = UniteDeMesure::all()->keyBy('symbole');

        // libellé => [catégorie, unité de stockage]
        return collect([
            'Tomate' => ['Fruits & Légumes', 'kg'],
            'Oignon' => ['Fruits & Légumes', 'kg'],
            'Pomme de terre' => ['Fruits & Légumes', 'kg'],
            'Banane plantain' => ['Fruits & Légumes', 'kg'],
            'Salade verte' => ['Fruits & Légumes', 'pce'],
            'Banane dessert' => ['Fruits & Légumes', 'kg'],
            'Poulet' => ['Viandes & Volailles', 'kg'],
            'Bœuf' => ['Viandes & Volailles', 'kg'],
            'Tilapia' => ['Produits de la mer', 'kg'],
            'Crevettes' => ['Produits de la mer', 'kg'],
            'Riz' => ['Épicerie', 'kg'],
            'Huile végétale' => ['Épicerie', 'L'],
            'Sel' => ['Épicerie', 'kg'],
            'Farine de blé' => ['Épicerie', 'sac'],
            'Sucre' => ['Épicerie', 'kg'],
            'Lait' => ['Crèmerie', 'L'],
            'Fromage' => ['Crèmerie', 'kg'],
            'Œufs' => ['Crèmerie', 'pce'],
        ])->map(fn (array $meta, string $libelle): MatierePremiere => MatierePremiere::factory()->create([
            'libelle' => $libelle,
            'categorie_id' => $categories[$meta[0]]->id,
            'unite_de_mesure_id' => $unites[$meta[1]]->id,
        ]))->values();
    }

    /**
     * @param  Collection<int, MatierePremiere>  $matieres
     * @param  Collection<int, UniteDeMesure>  $unites
     * @return Collection<int, Fournisseur>
     */
    private function creerFournisseursEtCatalogue(Collection $matieres, Collection $unites): Collection
    {
        return Fournisseur::factory()->count(5)->create()->each(
            function (Fournisseur $fournisseur) use ($matieres, $unites): void {
                $matieres->random(fake()->numberBetween(2, 5))->each(
                    fn (MatierePremiere $matiere) => CatalogueFournisseur::factory()->create([
                        'fournisseur_id' => $fournisseur->id,
                        'matiere_premiere_id' => $matiere->id,
                        'unite_achat_id' => $unites->random()->id,
                    ])
                );
            }
        );
    }

    /**
     * Un budget du mois courant par site (le budget est rattaché au site).
     *
     * @param  Collection<int, Site>  $sites
     */
    private function creerBudgets(Collection $sites): void
    {
        $sites->each(
            fn (Site $site) => Budget::factory()->create([
                'site_id' => $site->id,
                'mois' => now()->startOfMonth()->toDateString(),
            ])
        );
    }

    /**
     * Approvisionne le stock de chaque site via le StockService : peuple ainsi
     * la table `stocks` (cache par site) et `mouvements_stock`.
     *
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, MatierePremiere>  $matieres
     */
    private function approvisionnerStock(Collection $sites, Collection $matieres, StockService $stock): void
    {
        $sites->each(function (Site $site) use ($matieres, $stock): void {
            $matieres->random(6)->each(
                fn (MatierePremiere $matiere) => $stock->entree(
                    matierePremiereId: $matiere->id,
                    siteId: $site->id,
                    quantite: fake()->randomFloat(3, 5, 50),
                    prixUnitaireReel: fake()->randomFloat(4, 1, 30),
                    type: MouvementType::EntreeReception,
                    userId: null,
                )
            );
        });
    }

    /**
     * Sous-recettes (définitions partagées), créées via le service pour
     * déclencher le calcul du coût matière (PMP moyen multi-site).
     *
     * @param  Collection<int, MatierePremiere>  $matieres
     * @param  Collection<int, UniteDeMesure>  $unites
     * @return Collection<int, SousRecette>
     */
    private function creerSousRecettes(Collection $matieres, Collection $unites, string $userId): Collection
    {
        $service = app(SousRecetteService::class);
        $parMatiere = $matieres->keyBy('libelle');
        $parUnite = $unites->keyBy('symbole');

        // libellé => [unité produite, matières]
        $definitions = [
            'Fond de sauce' => ['L', ['Tomate', 'Oignon', 'Huile végétale']],
            'Pâte à beignets' => ['kg', ['Farine de blé', 'Sucre', 'Œufs']],
            'Bouillon maison' => ['L', ['Poulet', 'Oignon', 'Sel']],
        ];

        return collect($definitions)->map(fn (array $def, string $libelle): SousRecette => $service->creer([
            'libelle' => $libelle,
            'quantite_produite' => fake()->randomFloat(3, 1, 5),
            'unite_id' => $parUnite[$def[0]]->id,
            'lignes' => collect($def[1])->map(fn (string $nom): array => [
                'matiere_premiere_id' => $parMatiere[$nom]->id,
                'unite_id' => $parMatiere[$nom]->unite_de_mesure_id,
                'quantite' => fake()->randomFloat(3, 0.1, 2),
            ])->all(),
        ], $userId))->values();
    }

    /**
     * Produits réalistes, classés par catégorie, CHACUN avec sa fiche technique
     * active (matières cohérentes + sous-recette éventuelle), via le service
     * (versioning + coût de référence).
     *
     * @param  Collection<int, MatierePremiere>  $matieres
     * @param  Collection<int, SousRecette>  $sousRecettes
     * @return Collection<int, Produit>
     */
    private function creerProduitsEtFiches(Collection $matieres, Collection $sousRecettes, string $userId): Collection
    {
        $service = app(FicheTechniqueService::class);
        $categories = CategorieProduit::all()->keyBy('libelle');
        $parMatiere = $matieres->keyBy('libelle');
        $parSousRecette = $sousRecettes->keyBy('libelle');

        // libellé => [catégorie, matières, sous-recette éventuelle]
        $definitions = [
            'Salade composée' => ['Entrées', ['Salade verte', 'Tomate', 'Oignon'], null],
            'Crevettes sautées' => ['Entrées', ['Crevettes', 'Huile végétale', 'Sel'], null],
            'Poulet braisé' => ['Plats', ['Poulet', 'Oignon', 'Huile végétale'], 'Fond de sauce'],
            'Bœuf sauce tomate' => ['Plats', ['Bœuf', 'Tomate', 'Oignon'], 'Fond de sauce'],
            'Tilapia braisé' => ['Plats', ['Tilapia', 'Huile végétale', 'Sel'], null],
            'Riz sauté aux légumes' => ['Plats', ['Riz', 'Huile végétale', 'Oignon'], null],
            'Salade de fruits' => ['Desserts', ['Banane dessert', 'Sucre'], null],
            'Beignets sucrés' => ['Desserts', ['Farine de blé', 'Huile végétale'], 'Pâte à beignets'],
        ];

        return collect($definitions)->map(function (array $def, string $libelle) use ($service, $categories, $parMatiere, $parSousRecette, $userId): Produit {
            $produit = Produit::factory()->create([
                'libelle' => $libelle,
                'categorie_id' => $categories[$def[0]]->id,
            ]);

            $service->creer([
                'produit_id' => $produit->id,
                'lignes_matieres' => collect($def[1])->map(fn (string $nom): array => [
                    'matiere_premiere_id' => $parMatiere[$nom]->id,
                    'unite_id' => $parMatiere[$nom]->unite_de_mesure_id,
                    'quantite' => fake()->randomFloat(3, 0.05, 1.5),
                ])->all(),
                'lignes_sous_recettes' => $def[2] === null ? [] : [[
                    'sous_recette_id' => $parSousRecette[$def[2]]->id,
                    'quantite' => fake()->randomFloat(3, 0.1, 0.5),
                ]],
            ], $userId);

            return $produit;
        })->values();
    }

    /**
     * Compose chaque carte avec tous les produits (prix de vente logique par
     * catégorie + ordre dans la section), puis active la carte. Donne une carte
     * réaliste, regroupée par catégorie côté front.
     *
     * @param  Collection<int, Produit>  $produits
     */
    private function creerCompositionCartes(Collection $produits): void
    {
        $prixParCategorie = ['Entrées' => 2500, 'Plats' => 6000, 'Desserts' => 2000, 'Boissons' => 1500];
        $categories = CategorieProduit::all()->keyBy('id');

        Carte::all()->each(function (Carte $carte) use ($produits, $prixParCategorie, $categories): void {
            // Sous-ensemble : on laisse volontairement des produits hors carte pour
            // que le bouton « Ajouter un produit » reste actif en démo.
            $selection = $produits->shuffle()->take(fake()->numberBetween(4, 6))->values();

            $selection->each(function (Produit $produit, int $index) use ($carte, $prixParCategorie, $categories): void {
                $libelleCategorie = $categories[$produit->categorie_id]->libelle ?? '';
                $base = $prixParCategorie[$libelleCategorie] ?? 3000;

                CarteProduit::factory()->create([
                    'carte_id' => $carte->id,
                    'produit_id' => $produit->id,
                    'prix_vente' => $base + fake()->numberBetween(0, 1500),
                    'ordre' => $index,
                ]);
            });

            $carte->update(['statut' => CarteStatut::Active->value]);
        });
    }

    /**
     * Pour chaque site : une commande fournisseur envoyée puis une réception
     * confirmée (génère dépenses achat_fournisseur + entrées de stock du site).
     * Les lignes proviennent du catalogue du fournisseur (facteur de conversion
     * garanti).
     *
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, Fournisseur>  $fournisseurs
     */
    private function creerCommandesEtReceptions(Collection $sites, Collection $fournisseurs, string $userId): void
    {
        $commandeService = app(CommandeFournisseurService::class);
        $receptionService = app(ReceptionService::class);

        // Un fournisseur catalogué et jusqu'à 3 de ses matières.
        $lignesCatalogue = CatalogueFournisseur::query()
            ->whereIn('fournisseur_id', $fournisseurs->pluck('id'))
            ->get()
            ->groupBy('fournisseur_id')
            ->first()
            ->take(3);

        $fournisseurId = $lignesCatalogue->first()->fournisseur_id;

        $sites->each(function (Site $site) use ($lignesCatalogue, $fournisseurId, $commandeService, $receptionService, $userId): void {
            $commande = $commandeService->creer(
                ['fournisseur_id' => $fournisseurId],
                $lignesCatalogue->map(fn (CatalogueFournisseur $c): array => [
                    'matiere_premiere_id' => $c->matiere_premiere_id,
                    'unite_id' => $c->unite_achat_id,
                    'quantite_commandee' => 10,
                    'prix_unitaire_previsionnel' => (float) $c->prix_unitaire,
                ])->all(),
                $userId,
            );
            $commandeService->marquerEnvoyee($commande);

            $reception = new Reception([
                'commande_fournisseur_id' => $commande->id,
                'site_id' => $site->id,
                'date_reception' => now()->toDateString(),
            ]);
            $reception->user_id = $userId;
            $reception->save();

            $receptionService->confirmer(
                $reception,
                $lignesCatalogue->map(fn (CatalogueFournisseur $c): array => [
                    'matiere_premiere_id' => $c->matiere_premiere_id,
                    'unite_id' => $c->unite_achat_id,
                    'quantite_recue' => 10,
                    'prix_unitaire_reel' => (float) $c->prix_unitaire,
                ])->all(),
                $userId,
            );
        });
    }

    /**
     * Quelques charges hors stock saisies manuellement sur chaque budget.
     *
     * @param  Collection<int, Site>  $sites
     */
    private function creerChargesManuelles(Collection $sites): void
    {
        Budget::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->get()
            ->each(fn (Budget $budget) => Depense::factory()->count(2)->create(['budget_id' => $budget->id]));
    }

    /**
     * Un inventaire clôturé par site (génère les corrections de stock).
     *
     * @param  Collection<int, Site>  $sites
     * @param  Collection<int, MatierePremiere>  $matieres
     */
    private function creerInventaires(Collection $sites, Collection $matieres, string $userId): void
    {
        $service = app(InventaireService::class);

        $sites->each(function (Site $site) use ($matieres, $service, $userId): void {
            $inventaire = $service->creer(['site_id' => $site->id], $userId);

            $matieres->random(5)->each(fn (MatierePremiere $matiere) => $service->ajouterOuModifierLigne(
                $inventaire,
                ['matiere_premiere_id' => $matiere->id, 'quantite_physique' => fake()->randomFloat(3, 0, 40)],
                $userId,
            ));

            $service->cloturer($inventaire, $userId);
        });
    }
}
