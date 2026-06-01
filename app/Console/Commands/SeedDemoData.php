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
use App\Models\Fournisseur;
use App\Models\MatierePremiere;
use App\Models\ModeDePaiement;
use App\Models\PointDeVente;
use App\Models\Site;
use App\Models\UniteDeMesure;
use App\Models\User;
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
        $this->creerFournisseursEtCatalogue($matieres, $unites);
        $this->creerBudgets($sites);
        $this->approvisionnerStock($sites, $matieres, $stock);

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
     */
    private function creerFournisseursEtCatalogue(Collection $matieres, Collection $unites): void
    {
        Fournisseur::factory()->count(5)->create()->each(
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
}
