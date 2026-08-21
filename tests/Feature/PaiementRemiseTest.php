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

// ───────────────────────── pourboires ─────────────────────────

/**
 * Un pourboire est dans le tiroir mais n'appartient pas à l'établissement. Il
 * doit donc compter dans le théorique de la session — sinon il ressort comme un
 * excédent de caisse, c'est-à-dire comme un signal de vente non enregistrée —
 * sans jamais solder la note ni devenir de la recette.
 */
it('compte le pourboire dans le tiroir sans qu\'il solde la note', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 7500, 1);
    $mode = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);

    // Le client pose 10 000 sur une note de 7 500 et laisse 1 000.
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 7500,
        'pourboire' => 1000,
        'montant_recu' => 10000,
    ])->assertCreated()->assertJsonPath('data.pourboire', '1000.00');

    // La note est soldée par la seule vente.
    expect($commande->montantPaye())->toBe(7500.0)
        ->and($commande->resteAPayer())->toBe(0.0);

    // Le tiroir, lui, contient les deux.
    expect($ctx->session->montantEnCaisse())->toBe(8500.0)
        ->and($ctx->session->totalPourboires())->toBe(1000.0);
});

it('attribue le pourboire au serveur qui tient la note', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 5000, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 5000,
        'pourboire' => 500,
        'montant_recu' => 5500,
    ])->assertCreated();

    expect($ctx->session->pourboiresParServeur())->toBe([
        ['serveur' => trim($user->prenom.' '.$user->nom), 'montant' => 500.0],
    ]);
});

it('refuse un billet qui ne couvre pas la vente et le pourboire (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 7500, 1);
    $mode = ModeDePaiement::factory()->create();

    // 8 000 reçus ne peuvent pas couvrir 7 500 de vente ET 1 000 de pourboire.
    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 7500,
        'pourboire' => 1000,
        'montant_recu' => 8000,
    ])->assertStatus(422)->assertJsonValidationErrors('montant_recu');
});

it('accepte un pourboire sans espèces tendues (paiement par carte)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 5000, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 5000,
        'pourboire' => 750,
    ])->assertCreated()->assertJsonPath('data.pourboire', '750.00');

    expect($ctx->session->montantEnCaisse())->toBe(5750.0);
});

it('laisse le pourboire à zéro quand il n\'est pas fourni', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 50, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $mode->id,
        'montant' => 50,
    ])->assertCreated()->assertJsonPath('data.pourboire', '0.00');

    expect($ctx->session->totalPourboires())->toBe(0.0);
});

it('ne compte pas dans le tiroir le pourboire d\'un mode hors tiroir', function () {
    // Un pourboire sur virement existe (il est dû au serveur) mais n'est pas
    // dans la main du caissier le soir venu.
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 5000, 1);
    $virement = ModeDePaiement::factory()->horsTiroir()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", [
        'mode_de_paiement_id' => $virement->id,
        'montant' => 5000,
        'pourboire' => 500,
    ])->assertCreated();

    expect($ctx->session->montantEnCaisse())->toBe(0.0)
        ->and($ctx->session->montantHorsCaisse())->toBe(5500.0)
        ->and($ctx->session->totalPourboires())->toBe(500.0);
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

it('câble SessionCaisse::montantEnCaisse() sur les paiements de la session', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 80, 1);
    $mode = ModeDePaiement::factory()->create();

    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $mode->id, 'montant' => 80])->assertCreated();

    expect($ctx->session->montantEnCaisse())->toBe(80.0);
});

/**
 * Le tiroir ne contient que l'argent des modes qui y atterrissent. Un virement
 * est bien encaissé — la note est réglée — mais personne ne le retrouve en
 * comptant la caisse.
 */
it('sépare l\'argent du tiroir de celui qui n\'y passe pas', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['paiement.create', 'session_caisse.utiliser']);
    $commande = commandeServieAvecLigne($ctx, $user, 100, 1);
    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $virement = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Virement']);

    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $especes->id, 'montant' => 30])->assertCreated();
    postJson("/api/commandes-client/{$commande->id}/paiements", ['mode_de_paiement_id' => $virement->id, 'montant' => 70])->assertCreated();

    expect($ctx->session->montantEnCaisse())->toBe(30.0)
        ->and($ctx->session->montantHorsCaisse())->toBe(70.0);

    // La note, elle, est bien soldée : le filtre ne vaut que pour le tiroir.
    expect($commande->resteAPayer())->toBe(0.0);
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
