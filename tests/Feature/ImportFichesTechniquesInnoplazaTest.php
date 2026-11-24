<?php

declare(strict_types=1);

use App\Models\CategorieMatierePremiere;
use App\Models\FicheTechnique;
use App\Models\MatierePremiere;
use App\Models\Produit;
use App\Models\UniteDeMesure;
use Database\Seeders\CategorieMatierePremiereSeeder;
use Database\Seeders\CategorieProduitSeeder;
use Database\Seeders\MatierePremiereSeeder;
use Database\Seeders\UniteDeMesureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

function migrationImportInnoplaza(): object
{
    return require database_path('migrations/2026_11_19_110639_importer_fiches_techniques_innoplaza.php');
}

beforeEach(function (): void {
    seed([UniteDeMesureSeeder::class, CategorieMatierePremiereSeeder::class, MatierePremiereSeeder::class, CategorieProduitSeeder::class]);
});

it('crée une fiche active par plat et les planches en menus sans fiche', function (): void {
    migrationImportInnoplaza()->importer();

    expect(FicheTechnique::query()->where('statut', 'active')->count())->toBe(48)
        ->and(Produit::query()->where('est_menu', true)->pluck('libelle')->sort()->values()->all())
        ->toBe(['La Braise', 'Le Braisé', 'Le Feu de Brousse', 'Le Grand Feu', 'L’Étincelle'])
        ->and(FicheTechnique::query()->whereHas('produit', fn ($q) => $q->where('est_menu', true))->exists())->toBeFalse();
});

it('ramène les quantités du lot à une portion, en unité de stockage', function (): void {
    migrationImportInnoplaza()->importer();

    $sole = Produit::query()->where('libelle', 'Sole meunière')->firstOrFail();
    $lignes = FicheTechnique::query()->where('produit_id', $sole->id)->firstOrFail()
        ->lignesMatiere()->with('matierePremiere')->get()
        ->mapWithKeys(fn ($ligne): array => [$ligne->matierePremiere->libelle => (float) $ligne->quantite]);

    // 250 g + 60 g de beurre pour 10 soles ; 50 ml + 40 ml d'huile.
    expect($lignes['Beurre doux'])->toBe(0.031)
        ->and($lignes['Huile végétale'])->toBe(0.009)
        ->and($lignes['Persil (botte)'])->toBe(0.1)
        ->and($lignes)->not->toHaveKey('Sel')
        ->and($sole->categorie->libelle)->toBe('Plats');
});

it('reprend les matières déjà présentes au lieu de les dupliquer', function (): void {
    migrationImportInnoplaza()->importer();

    expect(MatierePremiere::query()->where('libelle', 'Oignon')->count())->toBe(1)
        ->and(MatierePremiere::query()->where('libelle', 'Huile végétale')->count())->toBe(1);
});

it('relie les burgers à la sauce en sous-recette', function (): void {
    migrationImportInnoplaza()->importer();

    $fiche = FicheTechnique::query()
        ->whereHas('produit', fn ($q) => $q->where('libelle', 'Burger Raclette'))
        ->with('lignesSousRecette.sousRecette')
        ->firstOrFail();

    expect($fiche->lignesSousRecette)->toHaveCount(1)
        ->and($fiche->lignesSousRecette->first()->sousRecette->libelle)->toBe('Sauce cocktail')
        ->and((float) $fiche->lignesSousRecette->first()->quantite)->toBe(0.03);
});

it('ne crée rien de plus quand on la rejoue', function (): void {
    migrationImportInnoplaza()->importer();
    $compte = fn (): array => [Produit::query()->count(), FicheTechnique::query()->count(), MatierePremiere::query()->count(), DB::table('sous_recettes')->count()];
    $avant = $compte();

    migrationImportInnoplaza()->importer();

    expect($compte())->toBe($avant);
});

it('refuse une matière existante stockée dans une autre unité, sans rien importer', function (): void {
    MatierePremiere::query()->create([
        'libelle' => 'Beurre doux',
        'categorie_id' => CategorieMatierePremiere::query()->value('id'),
        'unite_de_mesure_id' => UniteDeMesure::query()->where('symbole', 'pce')->value('id'),
    ]);

    expect(fn () => migrationImportInnoplaza()->importer())->toThrow(RuntimeException::class, 'Beurre doux');
    expect(FicheTechnique::query()->count())->toBe(0);
});

it('ne touche pas la base de test lors des migrations', function (): void {
    expect(FicheTechnique::query()->count())->toBe(0);
});
