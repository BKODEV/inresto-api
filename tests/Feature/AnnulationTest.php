<?php

declare(strict_types=1);

use App\Enums\StatutTable;
use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\FicheTechnique;
use App\Models\LigneCommandeClient;
use App\Models\LigneFicheTechnique;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

// ───────────────────────── annulation de ligne ─────────────────────────

/**
 * Ligne jamais partie en cuisine : rien n'a été produit ni déstocké. La retirer
 * n'est pas une annulation — sans motif, sans trace. Écrire une `annulation`
 * ici noierait celles qui comptent sous le bruit des « le client a changé
 * d'avis », qui sont fréquentes en salle.
 */
it('retire une ligne de brouillon sans motif ni trace', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'ouverte');
    $ligne = LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire' => 10]);

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}")
        ->assertOk();

    expect(LigneCommandeClient::find($ligne->id))->toBeNull()
        ->and(Annulation::count())->toBe(0)
        ->and($commande->fresh()->montant_total)->toBe('0.00');
});

/**
 * Dès que la ligne est partie, la cuisine a pu commencer : le motif redevient
 * légitime, et la ligne est conservée en `inactif` pour la piste d'audit.
 */
it('annule une ligne envoyée en cuisine : inactive, tracée, montant recalculé', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    $ligne = LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire' => 10]);

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}", ['motif' => 'plat renvoyé'])
        ->assertOk();

    expect($ligne->fresh()->statut->value)->toBe('inactif')
        ->and(Annulation::count())->toBe(1)
        ->and($commande->fresh()->montant_total)->toBe('0.00');
});

it('exige un motif pour annuler une ligne déjà envoyée (422)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'envoyee_en_cuisine');
    $ligne = LigneCommandeClient::factory()->envoyee()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id]);

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('motif');
});

it('annule une ligne servie avec remise_en_stock : mouvements entree créés', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $matiere = MatierePremiere::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechnique::factory()->create(['fiche_technique_id' => $fiche->id, 'matiere_premiere_id' => $matiere->id, 'quantite' => 2]);

    $commande = commandeSur($ctx, $user, 'servie');
    $ligne = LigneCommandeClient::factory()->servie()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => $produit->id,
        'quantite' => 3,
        'fiche_technique_id' => $fiche->id, // version figée
    ]);

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}", [
        'motif' => 'plat renvoyé',
        'sort' => 'remise_en_stock',
    ])->assertOk();

    $mvt = MouvementStock::query()
        ->where('matiere_premiere_id', $matiere->id)
        ->where('site_id', $ctx->site->id)
        ->where('type', 'entree_reception')
        ->first();

    expect($mvt)->not->toBeNull()->and((float) $mvt->quantite)->toBe(6.0); // 2 × 3
});

it('annule une ligne servie en perte : tracée, aucun mouvement supplémentaire', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $matiere = MatierePremiere::factory()->create();
    $fiche = FicheTechnique::factory()->create(['produit_id' => $produit->id]);
    LigneFicheTechnique::factory()->create(['fiche_technique_id' => $fiche->id, 'matiere_premiere_id' => $matiere->id, 'quantite' => 2]);

    $commande = commandeSur($ctx, $user, 'servie');
    $ligne = LigneCommandeClient::factory()->servie()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 3, 'fiche_technique_id' => $fiche->id]);

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}", [
        'motif' => 'jeté',
        'sort' => 'perte',
    ])->assertOk();

    expect(MouvementStock::count())->toBe(0);
});

it('annule une ligne servie sans fiche figée (remise_en_stock) : log, pas d\'erreur', function () {
    Log::spy();
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'servie');
    $ligne = LigneCommandeClient::factory()->servie()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1]); // fiche_technique_id null

    deleteJson("/api/commandes-client/{$commande->id}/lignes/{$ligne->id}", [
        'motif' => 'renvoyé',
        'sort' => 'remise_en_stock',
    ])->assertOk();

    expect(MouvementStock::count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'REMISE_EN_STOCK_IMPOSSIBLE');
});

// ───────────────────────── annulation de commande ─────────────────────────

it('annule une commande ouverte : annulee, table libre, lignes inactives', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $ctx->table->statut = StatutTable::Occupee;
    $ctx->table->save();
    $commande = commandeSur($ctx, $user, 'ouverte');
    $ligne = LigneCommandeClient::factory()->create(['commande_client_id' => $commande->id, 'produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 10]);

    postJson("/api/commandes-client/{$commande->id}/annuler", ['motif' => 'client parti'])
        ->assertOk()
        ->assertJsonPath('data.statut', 'annulee');

    expect($ctx->table->fresh()->statut)->toBe(StatutTable::Libre)
        ->and($ligne->fresh()->statut->value)->toBe('inactif');
});

it('refuse l\'annulation d\'une commande servie (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $commande = commandeSur($ctx, $user, 'servie');

    postJson("/api/commandes-client/{$commande->id}/annuler", ['motif' => 'trop tard'])->assertStatus(409);
});

it('refuse l\'annulation d\'une commande encaissée (409)', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.create', 'session_caisse.utiliser']);
    $commande = CommandeClient::factory()->state(['statut' => 'encaissee'])->create([
        'table_id' => $ctx->table->id,
        'session_caisse_id' => $ctx->session->id,
        'user_id' => $user->id,
    ]);

    postJson("/api/commandes-client/{$commande->id}/annuler", ['motif' => 'déjà payé'])->assertStatus(409);
});

// ───────────────────────── permissions ─────────────────────────

it('refuse une annulation sans la permission annulation.create', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['session_caisse.utiliser']);
    $produit = produitSurCarte($ctx, 10);
    $commande = commandeSur($ctx, $user, 'ouverte');

    postJson("/api/commandes-client/{$commande->id}/annuler", ['motif' => 'x'])->assertForbidden();
});

// ───────────────────────── relecture (index) ─────────────────────────

/**
 * `annulation.view` figurait au catalogue de permissions et la policy portait
 * déjà `viewAny` — sans aucune route pour les consommer : l'accorder à un rôle
 * n'ouvrait rien. Le journal montre les annulations au fil du service ; cette
 * liste répond à la question de contrôle, sur une période et par sort.
 */
it('liste les annulations, de la plus récente à la plus ancienne', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.view']);
    $commande = commandeSur($ctx, $user);

    Annulation::factory()->create([
        'annulable_type' => 'commandes_client', 'annulable_id' => $commande->id,
        'user_id' => $user->id, 'motif' => 'ancienne', 'date_heure' => now()->subHour(),
    ]);
    Annulation::factory()->create([
        'annulable_type' => 'commandes_client', 'annulable_id' => $commande->id,
        'user_id' => $user->id, 'motif' => 'récente', 'date_heure' => now(),
    ]);

    getJson('/api/annulations')
        ->assertOk()
        ->assertJsonCount(2, 'data.annulations')
        ->assertJsonPath('data.annulations.0.motif', 'récente')
        ->assertJsonPath('data.annulations.1.motif', 'ancienne')
        ->assertJsonPath('data.pagination.total', 2);
});

it('refuse la relecture sans la permission dédiée', function () {
    // Annuler et relire les annulations sont deux droits distincts : un serveur
    // annule ses propres lignes sans avoir à consulter celles de la maison.
    $ctx = salleContext();
    actingAsServeur($ctx->site, ['annulation.create']);

    getJson('/api/annulations')->assertForbidden();
});

/**
 * La question de contrôle : ce qui a été annulé APRÈS service est-il reparti en
 * stock, ou passé en perte. Sans ce filtre il faut la lire à l'œil.
 */
it('filtre sur le devenir du stock', function () {
    $ctx = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.view']);
    $commande = commandeSur($ctx, $user);

    foreach (['perte', 'remise_en_stock', null] as $sort) {
        Annulation::factory()->create([
            'annulable_type' => 'commandes_client', 'annulable_id' => $commande->id,
            'user_id' => $user->id, 'sort' => $sort, 'date_heure' => now(),
        ]);
    }

    getJson('/api/annulations?sort=perte')
        ->assertOk()
        ->assertJsonCount(1, 'data.annulations')
        ->assertJsonPath('data.annulations.0.sort', 'perte');
});

it('refuse un devenir de stock inconnu (422)', function () {
    $ctx = salleContext();
    actingAsServeur($ctx->site, ['annulation.view']);

    getJson('/api/annulations?sort=poubelle')
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

/**
 * Une annulation n'a pas de site à elle : elle le tient de ce qu'elle annule —
 * une commande par sa table, une LIGNE par sa commande puis sa table. Deux
 * chemins, et le périmètre doit tenir sur les deux.
 */
it('borne au périmètre de sites, ligne comme commande', function () {
    $ctx = salleContext();
    $autre = salleContext();
    $user = actingAsServeur($ctx->site, ['annulation.view']);

    $chezMoi = commandeSur($ctx, $user);
    $ailleurs = commandeSur($autre, $user);
    $ligneAilleurs = LigneCommandeClient::factory()->create([
        'commande_client_id' => $ailleurs->id,
        'produit_id' => produitSurCarte($autre)->id,
    ]);

    Annulation::factory()->create([
        'annulable_type' => 'commandes_client', 'annulable_id' => $chezMoi->id,
        'user_id' => $user->id, 'motif' => 'la mienne', 'date_heure' => now(),
    ]);
    Annulation::factory()->create([
        'annulable_type' => 'commandes_client', 'annulable_id' => $ailleurs->id,
        'user_id' => $user->id, 'motif' => 'hors périmètre', 'date_heure' => now(),
    ]);
    Annulation::factory()->create([
        'annulable_type' => 'lignes_commande_client', 'annulable_id' => $ligneAilleurs->id,
        'user_id' => $user->id, 'motif' => 'ligne hors périmètre', 'date_heure' => now(),
    ]);

    getJson('/api/annulations')
        ->assertOk()
        ->assertJsonCount(1, 'data.annulations')
        ->assertJsonPath('data.annulations.0.motif', 'la mienne');
});
