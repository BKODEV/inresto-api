<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MouvementType;
use App\Models\Budget;
use App\Models\Caisse;
use App\Models\Carte;
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
        $this->creerProduitsEtFiches($matieres, $sousRecettes, $userId);
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
     * Un utilisateur d'exploitation par site, rattaché à ce site comme site
     * principal. Le périmètre métier de l'utilisateur est le site.
     *
     * @param  Collection<int, Site>  $sites
     */
    private function creerUtilisateurs(Collection $sites): void
    {
        $sites->each(function (Site $site): void {
            $user = User::factory()->create();

            $user->sites()->attach($site->id, ['est_principal' => true]);
        });
    }

    private function creerParametres(): void
    {
        CategorieProduit::factory()->count(4)->create();

        CategorieMatierePremiere::factory()
            ->count(4)
            ->sequence(
                ['libelle' => 'Fruits & Légumes'],
                ['libelle' => 'Viandes & Volailles'],
                ['libelle' => 'Produits de la mer'],
                ['libelle' => 'Épicerie'],
            )
            ->create();

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
        return MatierePremiere::factory()
            ->count(12)
            ->recycle(CategorieMatierePremiere::all())
            ->recycle(UniteDeMesure::all())
            ->create();
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

        return collect(['Fond de sauce', 'Pâte à pizza', 'Bouillon maison'])
            ->map(fn (string $libelle): SousRecette => $service->creer([
                'libelle' => $libelle,
                'quantite_produite' => fake()->randomFloat(3, 1, 10),
                'unite_id' => $unites->random()->id,
                'lignes' => $matieres->random(3)->map(fn (MatierePremiere $matiere): array => [
                    'matiere_premiere_id' => $matiere->id,
                    'unite_id' => $matiere->unite_de_mesure_id,
                    'quantite' => fake()->randomFloat(3, 1, 5),
                ])->all(),
            ], $userId));
    }

    /**
     * Produits + leur fiche technique active (matières + sous-recettes), via le
     * service (versioning + coût de référence).
     *
     * @param  Collection<int, MatierePremiere>  $matieres
     * @param  Collection<int, SousRecette>  $sousRecettes
     */
    private function creerProduitsEtFiches(Collection $matieres, Collection $sousRecettes, string $userId): void
    {
        $service = app(FicheTechniqueService::class);

        Produit::factory()->count(4)->recycle(CategorieProduit::all())->create()->each(
            fn (Produit $produit) => $service->creer([
                'produit_id' => $produit->id,
                'lignes_matieres' => $matieres->random(3)->map(fn (MatierePremiere $matiere): array => [
                    'matiere_premiere_id' => $matiere->id,
                    'unite_id' => $matiere->unite_de_mesure_id,
                    'quantite' => fake()->randomFloat(3, 1, 4),
                ])->all(),
                'lignes_sous_recettes' => $sousRecettes->random(1)->map(fn (SousRecette $sousRecette): array => [
                    'sous_recette_id' => $sousRecette->id,
                    'quantite' => fake()->randomFloat(3, 1, 3),
                ])->all(),
            ], $userId)
        );
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
