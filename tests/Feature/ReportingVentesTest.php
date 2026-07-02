<?php

declare(strict_types=1);

use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\PointDeVente;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * Crée une commande vendue sur la table donnée avec une ligne (quantite × prix).
 */
function commandeVendue(TableSalle $table, int $quantite, float $prix, string $statut = 'servie', ?string $userId = null): CommandeClient
{
    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'statut' => $statut,
        'date_heure' => now(),
        'user_id' => $userId ?? User::factory()->create()->id,
    ]);

    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'quantite' => $quantite,
        'prix_unitaire' => $prix,
    ]);

    return $commande;
}

it('calcule le CA et le panier moyen par point de vente', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    commandeVendue($table, 2, 10); // CA 20
    commandeVendue($table, 1, 30); // CA 30 → total 50 sur 2 commandes

    $rows = getJson('/api/reporting/ventes/points-de-vente')->assertOk()->json('data.points_de_vente');

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['ca'])->toBe(50.0)
        ->and($rows[0]['nombre_commandes'])->toBe(2)
        ->and((float) $rows[0]['panier_moyen'])->toBe(25.0);
});

it('compte une commande servie dans le CA sans exiger l\'encaissement', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    commandeVendue($table, 1, 42, 'servie');

    $rows = getJson('/api/reporting/ventes/points-de-vente')->assertOk()->json('data.points_de_vente');

    expect((float) $rows[0]['ca'])->toBe(42.0);
});

it('respecte le tri et la limite du top produits', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);

    // Produit A : grosse quantité, petit CA. Produit B : petite quantité, gros CA.
    $commande = CommandeClient::factory()->create(['table_id' => $table->id, 'statut' => 'servie', 'date_heure' => now()]);
    $produitA = Produit::factory()->create();
    $produitB = Produit::factory()->create();
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produitA->id, 'quantite' => 10, 'prix_unitaire' => 1]); // CA 10
    LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produitB->id, 'quantite' => 1, 'prix_unitaire' => 100]); // CA 100

    // Tri par CA desc, limit 1 → B en tête.
    $parCa = getJson('/api/reporting/ventes/produits?tri=ca&ordre=desc&limit=1')->assertOk()->json('data.produits');
    expect($parCa)->toHaveCount(1)
        ->and($parCa[0]['produit_id'])->toBe($produitB->id);

    // Tri par quantité desc, limit 1 → A en tête.
    $parQte = getJson('/api/reporting/ventes/produits?tri=quantite&ordre=desc&limit=1')->assertOk()->json('data.produits');
    expect($parQte[0]['produit_id'])->toBe($produitA->id);
});

it('base les modes de paiement sur l\'encaissé (commandes encaissées)', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $mode = ModeDePaiement::factory()->create();

    $encaissee = commandeVendue($table, 1, 50, 'encaissee');
    Paiement::factory()->create(['commande_client_id' => $encaissee->id, 'mode_de_paiement_id' => $mode->id, 'montant' => 50]);

    // Une commande seulement servie : un paiement existe mais ne doit PAS compter (pas encaissée).
    $servie = commandeVendue($table, 1, 99, 'servie');
    Paiement::factory()->create(['commande_client_id' => $servie->id, 'mode_de_paiement_id' => $mode->id, 'montant' => 99]);

    $rows = getJson('/api/reporting/ventes/modes-paiement')->assertOk()->json('data.modes_paiement');

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]['montant_total'])->toBe(50.0)
        ->and($rows[0]['nombre_paiements'])->toBe(1)
        ->and((float) $rows[0]['part_pct'])->toBe(100.0);
});

it('agrège le CA par serveur (user de la commande)', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $pdv = PointDeVente::factory()->create(['site_id' => Site::factory()->create()->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $serveur = User::factory()->create();

    commandeVendue($table, 1, 20, 'servie', $serveur->id);
    commandeVendue($table, 1, 30, 'servie', $serveur->id);

    $rows = getJson('/api/reporting/ventes/serveurs')->assertOk()->json('data.serveurs');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['user_id'])->toBe($serveur->id)
        ->and((float) $rows[0]['ca'])->toBe(50.0)
        ->and($rows[0]['nombre_commandes'])->toBe(2);
});

it('retourne le bon nombre de points selon la période (évolution)', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $jour = getJson('/api/reporting/ventes/evolution?periode=jour&date=2026-06-23')->assertOk()->json('data.serie');
    expect($jour)->toHaveCount(24); // 24 heures

    $semaine = getJson('/api/reporting/ventes/evolution?periode=semaine&date=2026-06-23')->assertOk()->json('data.serie');
    expect($semaine)->toHaveCount(7); // 7 jours

    $plage = getJson('/api/reporting/ventes/evolution?periode=plage&date_debut=2026-06-01&date_fin=2026-06-10')->assertOk()->json('data.serie');
    expect($plage)->toHaveCount(10); // 10 jours inclus
});
