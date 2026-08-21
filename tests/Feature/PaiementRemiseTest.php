<?php

declare(strict_types=1);

use App\Enums\StatutCommandeClient;
use App\Enums\StatutTable;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\ModeDePaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * Commande servie avec une ligne (montant des lignes = quantite × prix).
 * Réutilise les helpers globaux salleContext / actingAsServeur / produitSurCarte
 * / commandeSur définis dans CommandeClientTest.
 */
function commandeServieAvecLigne(object $ctx, $user, float $prix, int $quantite): CommandeClient
{
    $produit = produitSurCarte($ctx, $prix);
    $commande = commandeSur($ctx, $user, 'servie');
    // Le cycle de vie vit sur la ligne : une note « servie » dont les lignes ne
    // le sont pas serait incohérente.
    LigneCommandeClient::factory()->servie()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produit->id,
        'quantite' => $quantite,
        'prix_unitaire' => $prix,
    ]);

    return $commande;
}

// ───────────────────────── paiements ─────────────────────────

it('enregistre un paiement sur une commande servie', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 50, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 50,
    ])->assertCreated();

    expect($commande->montantPaye())->toBe(50.0);
});

/**
 * Le billet tendu et l'argent qui reste en tiroir sont deux montants distincts.
 * `montant` alimente `montant_theorique` de la session : y inscrire le billet
 * ferait compter comme en caisse la monnaie déjà rendue, et sortirait un
 * manquant à chaque fermeture.
 */
it('mémorise les espèces tendues sans les compter comme encaissées', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 7500, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 7500,
        'montant_recu' => 10000,
    ])->assertCreated()
        ->assertJsonPath('data.montant_recu', '10000.00');

    // 2 500 rendus au client : ils ne sont jamais entrés en caisse.
    expect($commande->montantPaye())->toBe(7500.0)
        ->and($commande->resteAPayer())->toBe(0.0);
});

it('refuse un montant reçu inférieur au montant encaissé (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 7500, 1);
    $mode = ModeDePaiement::factory()->create();

    // Les deux champs inversés : on ne rend pas plus que ce qu'on a reçu.
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 7500,
        'montant_recu' => 5000,
    ])->assertStatus(422)->assertJsonValidationErrors('montant_recu');
});

it('accepte un paiement sans espèces tendues (carte, appoint juste)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 50, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 50,
    ])->assertCreated()->assertJsonPath('data.montant_recu', null);
});

it('refuse un paiement sur une commande ouverte (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'ouverte');
    LigneCommandeClient::factory()->servie()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 10]);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 10,
    ])->assertStatus(422);
});

it('gère un paiement fractionné : reste_a_payer correct', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 60])->assertCreated();
    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 25])
        ->assertCreated()
        ->assertJsonPath('data.id', fn ($id) => $id !== null);

    expect($commande->resteAPayer())->toBe(15.0);
});

it('câble SessionCaisse::montantPaye() sur les paiements de la session', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 80, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 80])->assertCreated();

    expect($ctx->session->montantPaye())->toBe(80.0);
});

// ───────────────────────── remises ─────────────────────────

it('applique une remise sous le seuil sans permission spéciale', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['remise.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);

    postJson("/api/commandes-client/{$commande->id}/remises", [
        'type' => 'pourcentage',
        'valeur' => 10, // 10 % <= seuil 15
    ])->assertCreated();

    expect($commande->totalRemises())->toBe(10.0);
});

it('refuse une remise au-delà du seuil sans permission (403)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['remise.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);

    postJson("/api/commandes-client/{$commande->id}/remises", [
        'type' => 'pourcentage',
        'valeur' => 20, // 20 % > seuil 15
    ])->assertStatus(403);
});

it('autorise une remise au-delà du seuil avec la permission', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['remise.create', 'remise.autoriser_au_dela_seuil', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);

    postJson("/api/commandes-client/{$commande->id}/remises", [
        'type' => 'pourcentage',
        'valeur' => 20,
    ])->assertCreated();
});

it('refuse une remise sur une commande encaissée (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['remise.create', 'remise.autoriser_au_dela_seuil', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $commande->statut = StatutCommandeClient::Encaissee;
    $commande->save();

    postJson("/api/commandes-client/{$commande->id}/remises", [
        'type' => 'pourcentage',
        'valeur' => 5,
    ])->assertStatus(409);
});

// ───────────────────────── encaissement ─────────────────────────

it('refuse l\'encaissement s\'il reste à payer (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.encaisser', 'paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $mode = ModeDePaiement::factory()->create();
    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 50])->assertCreated();

    postJson("/api/commandes-client/{$commande->id}/encaisser")->assertStatus(422);
});

it('encaisse une commande soldée : statut encaissee, table à débarrasser', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.encaisser', 'paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $mode = ModeDePaiement::factory()->create();
    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 100])->assertCreated();

    postJson("/api/commandes-client/{$commande->id}/encaisser")
        ->assertOk()
        ->assertJsonPath('data.statut', 'encaissee');

    expect($commande->fresh()->statut->value)->toBe('encaissee')
        ->and($ctx->table->fresh()->statut)->toBe(StatutTable::ADebarrasser);
});

/**
 * Les paiements portent déjà leur auteur, mais une note soldée sans aucun
 * paiement (offerte, couverte par un avoir) n'en a aucun : sans signature de la
 * clôture, plus personne ne sait qui a fermé la note.
 */
it('signe la clôture du nom de l\'encaisseur', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['commande_client.encaisser', 'paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $mode = ModeDePaiement::factory()->create();
    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 100])->assertCreated();

    expect($commande->fresh()->encaisse_par)->toBeNull();

    postJson("/api/commandes-client/{$commande->id}/encaisser")->assertOk();

    expect($commande->fresh()->encaisse_par)->toBe($user->id)
        ->and($commande->fresh()->encaisse_le)->not->toBeNull();
});

// ───────────────────────── permissions ─────────────────────────

it('refuse un paiement sans la permission paiement.create', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 10, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 10,
    ])->assertForbidden();
});
