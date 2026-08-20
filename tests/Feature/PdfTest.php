<?php

declare(strict_types=1);

use App\Models\CatalogueFournisseur;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\LigneCommandeFournisseur;
use App\Models\LigneFicheTechnique;
use App\Models\MatierePremiere;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\ParametreEtablissement;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Remise;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\UniteDeMesure;
use App\Models\User;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * @param  string[]  $permissions
 */
function actingWithPdfPerms(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $name) {
        Permission::findOrCreate($name, 'api');
    }

    $user->givePermissionTo($permissions);
    Sanctum::actingAs($user);

    return $user;
}

/**
 * Commande client complète (site → PDV → table) avec une ligne, une remise fixe
 * et un paiement. $avecParametres contrôle la présence de la fiche établissement.
 */
function pdfCommandeClient(bool $avecParametres = true): CommandeClient
{
    $site = Site::factory()->create();
    if ($avecParametres) {
        ParametreEtablissement::factory()->create(['site_id' => $site->id, 'devise' => 'XOF']);
    }
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $session = SessionCaisse::factory()->create();

    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'session_caisse_id' => $session->id,
    ]);

    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => Produit::factory()->create(['libelle' => 'Café'])->id,
        'quantite' => 2,
        'prix_unitaire' => 1000,
    ]);

    Remise::factory()->montantFixe(500)->create(['commande_client_id' => $commande->id]);

    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $mode->id,
        'montant' => 1500,
    ]);

    return $commande;
}

it('génère le bon de commande fournisseur en PDF', function () {
    $user = actingWithPdfPerms(['commande-fournisseur.view']);
    $site = Site::factory()->create();
    rattacherAuxSites($user, $site);
    ParametreEtablissement::factory()->create(['site_id' => $site->id]);
    $commande = CommandeFournisseur::factory()->create();
    LigneCommandeFournisseur::factory()->count(2)->create(['commande_fournisseur_id' => $commande->id]);

    getJson("/api/commandes-fournisseur/{$commande->id}/pdf?site_id={$site->id}")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('génère la facture client en PDF', function () {
    actingWithPdfPerms(['commande_client.view']);
    $commande = pdfCommandeClient();

    getJson("/api/commandes-client/{$commande->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('génère le reçu client en PDF', function () {
    actingWithPdfPerms(['commande_client.view']);
    $commande = pdfCommandeClient();

    getJson("/api/commandes-client/{$commande->id}/recu")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('génère la fiche technique en PDF', function () {
    actingWithPdfPerms(['fichetechnique.view']);
    $fiche = FicheTechnique::factory()->create();
    $matiere = MatierePremiere::factory()->create();
    LigneFicheTechnique::factory()->create([
        'fiche_technique_id' => $fiche->id,
        'matiere_premiere_id' => $matiere->id,
        'unite_id' => UniteDeMesure::factory()->create()->id,
        'quantite' => 2,
    ]);
    CatalogueFournisseur::factory()->create([
        'matiere_premiere_id' => $matiere->id,
        'prix_unitaire' => 100,
        'qte_par_unite_achat' => 1,
    ]);

    getJson("/api/fiches-techniques/{$fiche->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('titre la fiche technique avec l\'établissement du site demandé', function () {
    // La fiche est une définition partagée : elle ne porte aucun site. L'en-tête
    // vient donc du site d'impression, et non plus du seul site PRINCIPAL —
    // lequel n'a pas forcément de fiche d'établissement.
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create([
        'site_id' => $site->id,
        'nom_commercial' => 'Chez Awa',
    ]);
    $fiche = FicheTechnique::factory()->create();

    $html = app(PdfService::class)->htmlFicheTechnique($fiche, $site->id);

    expect($html)->toContain('Chez Awa')
        ->and($html)->not->toContain('Paramètres établissement non configurés');
});

it('refuse la fiche technique en PDF pour un site hors périmètre (403)', function () {
    $sien = Site::factory()->create();
    $autre = Site::factory()->create();
    $user = actingWithPdfPerms(['fichetechnique.view']);
    $user->sites()->sync([$sien->id]);

    $fiche = FicheTechnique::factory()->create();

    getJson("/api/fiches-techniques/{$fiche->id}/pdf?site_id={$autre->id}")->assertForbidden();
});

it('rend une facture avec des montants cohérents (remise incluse)', function () {
    $commande = pdfCommandeClient();

    $html = app(PdfService::class)->htmlFactureClient($commande);

    // Sous-total 2 × 1 000 = 2 000 ; remise fixe 500 ; total dû 1 500.
    expect($html)
        ->toContain('2 000 XOF')   // sous-total
        ->toContain('1 500 XOF')   // total dû / payé
        ->toContain('Café');
});

it('affiche le bandeau quand le site n\'a pas de fiche de paramètres', function () {
    $commande = pdfCommandeClient(avecParametres: false);

    $html = app(PdfService::class)->htmlFactureClient($commande);

    expect($html)->toContain('Paramètres établissement non configurés');
});

/**
 * Un document imprimé doit rester identique à lui-même. Sans numéro figé, les
 * gabarits relisaient la table à chaque impression : renommer T07 en T12
 * réécrivait toutes ses factures passées, qui ne correspondaient plus au ticket
 * remis au client. La facture normalisée impose l'inverse.
 */
it('réimprime une facture avec le numéro de table du jour de la commande', function () {
    $commande = pdfCommandeClient();
    $commande->update(['table_numero' => 'T07']);
    $commande->table->update(['numero' => 'T12']);

    $html = app(PdfService::class)->htmlFactureClient($commande->fresh());

    expect($html)->toContain('T07')
        ->and($html)->not->toContain('T12');
});

/**
 * Garde-fou du choix de conception de l'archivage.
 *
 * L'archivage passe par une colonne `archivee_le` et non par `SoftDeletes` :
 * un scope global exclurait la table de `CommandeClient::table()`, et ce
 * gabarit lit `$commande->table->numero` à l'impression. Passer un jour au
 * soft delete casserait donc la réimpression de tout l'historique — ce test
 * échouerait avant que quiconque s'en aperçoive en production.
 */
it('réimprime la facture d\'une table archivée avec son numéro', function () {
    $commande = pdfCommandeClient();
    $commande->table->update(['numero' => 'T07', 'archivee_le' => now()]);

    $html = app(PdfService::class)->htmlFactureClient($commande->fresh());

    expect($html)->toContain('T07');
});

it('affiche la référence de facture normalisée si présente', function () {
    $commande = pdfCommandeClient();
    $commande->update(['reference_facture_normalisee' => 'FNE-2026-0001']);

    $html = app(PdfService::class)->htmlFactureClient($commande->fresh());

    expect($html)
        ->toContain('Réf. facture normalisée')
        ->toContain('FNE-2026-0001');
});

it('refuse la facture sans la permission de lecture', function () {
    actingWithPdfPerms([]);
    $commande = pdfCommandeClient();

    getJson("/api/commandes-client/{$commande->id}/pdf")->assertForbidden();
});

it('refuse le bon de commande sans la permission de lecture', function () {
    actingWithPdfPerms([]);
    $commande = CommandeFournisseur::factory()->create();

    getJson("/api/commandes-fournisseur/{$commande->id}/pdf")->assertForbidden();
});

it('refuse la fiche technique sans la permission de lecture', function () {
    actingWithPdfPerms([]);
    $fiche = FicheTechnique::factory()->create();

    getJson("/api/fiches-techniques/{$fiche->id}/pdf")->assertForbidden();
});

it('ne contient aucune donnée d\'établissement codée en dur (revente)', function () {
    $fichiers = glob(resource_path('views/pdf/**/*.blade.php'));
    $fichiers = array_merge($fichiers, glob(resource_path('views/pdf/*.blade.php')));

    foreach ($fichiers as $fichier) {
        expect(file_get_contents($fichier))->not->toContain('Innov');
    }
});
