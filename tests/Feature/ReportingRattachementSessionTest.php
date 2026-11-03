<?php

declare(strict_types=1);

use App\Enums\StatutCommandeClient;
use App\Models\Caisse;
use App\Models\CommandeClient;
use App\Models\LigneCommandeClient;
use App\Models\ModeDePaiement;
use App\Models\Paiement;
use App\Models\PointDeVente;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\TableSalle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

/**
 * Un service : une session de caisse ouverte à l'instant dit, sur son propre
 * site, avec la table de salle qui va avec.
 *
 * @return array{0: SessionCaisse, 1: TableSalle}
 */
function serviceOuvertLe(string $ouvertureLe, ?Site $site = null): array
{
    $site ??= Site::factory()->create();
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $caisse = Caisse::factory()->create(['point_de_vente_id' => $pdv->id]);

    $session = SessionCaisse::factory()->create(['caisse_id' => $caisse->id]);
    // `ouvert_le` n'est pas fillable : seul le service l'écrit à l'ouverture.
    $session->ouvert_le = Carbon::parse($ouvertureLe);
    $session->save();

    return [$session, TableSalle::factory()->create(['point_de_vente_id' => $pdv->id])];
}

/** Une note vendue sur ce service, horodatée à la minute près. */
function noteDuService(SessionCaisse $session, TableSalle $table, string $quand, float $montant): CommandeClient
{
    $commande = CommandeClient::factory()->create([
        'session_caisse_id' => $session->id,
        'table_id' => $table->id,
        'statut' => 'servie',
        'date_heure' => Carbon::parse($quand),
        'user_id' => User::factory()->create()->id,
    ]);

    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'quantite' => 1,
        'prix_unitaire' => $montant,
    ]);

    return $commande;
}

/*
|--------------------------------------------------------------------------
| Une vente appartient au service qui l'a produite
|--------------------------------------------------------------------------
|
| Un service qui ouvre le 3 à 18h et ferme le 4 à 2h coupait sa soirée en deux
| sur le calendrier : le chiffre du 3 était amputé de ses dernières tables, et
| celui du 4 gonflé d'un service auquel il n'avait pas participé.
|
*/

it('rattache toute la soirée au jour où le service a ouvert', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($session, $table, '2026-06-23 22:00:00', 10_000);
    // Le client est encore là à 1h30 : c'est toujours le service du 23.
    noteDuService($session, $table, '2026-06-24 01:30:00', 5_000);

    $lignes = getJson('/api/reporting/ventes/points-de-vente?periode=jour&date=2026-06-23')
        ->assertOk()->json('data.points_de_vente');

    expect((float) $lignes[0]['ca'])->toBe(15_000.0)
        ->and($lignes[0]['nombre_commandes'])->toBe(2);
});

it('ne fait pas compter la fin de soirée dans le jour suivant', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($session, $table, '2026-06-24 01:30:00', 5_000);

    $lignes = getJson('/api/reporting/ventes/points-de-vente?periode=jour&date=2026-06-24')
        ->assertOk()->json('data.points_de_vente');

    expect($lignes)->toBeEmpty();
});

it('sépare deux services ouverts des jours différents', function () {
    $site = Site::factory()->create();
    [$veille, $tableVeille] = serviceOuvertLe('2026-06-23 18:00:00', $site);
    [$jour, $tableJour] = serviceOuvertLe('2026-06-24 11:00:00', $site);

    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($veille, $tableVeille, '2026-06-24 01:00:00', 40_000);
    noteDuService($jour, $tableJour, '2026-06-24 12:00:00', 7_000);

    $duJour = getJson('/api/reporting/ventes/points-de-vente?periode=jour&date=2026-06-24')
        ->assertOk()->json('data.points_de_vente');

    // Les deux notes sont du 24 à l'horloge, mais d'un seul des deux services.
    expect((float) $duJour[0]['ca'])->toBe(7_000.0);
});

/**
 * La série journalière doit dire la même chose que le rapport du même jour,
 * sans quoi on a deux chiffres et aucun moyen de trancher.
 */
it('range la série journalière sur le jour d\'ouverture du service', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($session, $table, '2026-06-23 22:00:00', 10_000);
    noteDuService($session, $table, '2026-06-24 01:30:00', 5_000);

    $serie = getJson('/api/reporting/ventes/evolution?periode=plage&date_debut=2026-06-23&date_fin=2026-06-24')
        ->assertOk()->json('data.serie');

    $parLabel = collect($serie)->keyBy('label');

    expect($serie)->toHaveCount(2)
        ->and((float) $parLabel['2026-06-23']['ca'])->toBe(15_000.0)
        ->and((float) $parLabel['2026-06-24']['ca'])->toBe(0.0);
});

/**
 * Le JOUR vient de la session, l'HEURE reste celle de la note : c'est le moment
 * réel du coup de feu. Les rattacher tous à l'ouverture les écraserait sur une
 * seule barre.
 */
it('garde l\'heure réelle de la note dans la série horaire', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($session, $table, '2026-06-23 20:00:00', 10_000);
    noteDuService($session, $table, '2026-06-23 22:00:00', 5_000);

    $serie = collect(getJson('/api/reporting/ventes/evolution?periode=jour&date=2026-06-23')
        ->assertOk()->json('data.serie'))->keyBy('label');

    expect($serie)->toHaveCount(24)
        ->and((float) $serie['20h']['ca'])->toBe(10_000.0)
        ->and((float) $serie['22h']['ca'])->toBe(5_000.0);
});

/**
 * Le food cost lit les mêmes lignes que le CA : il doit suivre le même
 * rattachement, sinon la marge d'une soirée se calculerait sur deux jours.
 */
it('applique le même rattachement au food cost', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.food_cost', 'reporting.global']);

    noteDuService($session, $table, '2026-06-24 01:30:00', 12_000);

    $synthese = getJson('/api/reporting/food-cost/synthese?periode=jour&date=2026-06-23')
        ->assertOk()->json('data');

    expect((float) $synthese['ca_total'])->toBe(12_000.0);
});

/**
 * Le rattachement vaut pour ce qui PASSE par une caisse. L'encaissé est de
 * cette famille : c'est le même service qui a reçu l'argent.
 */
it('rattache aussi l\'encaissé au service', function () {
    [$session, $table] = serviceOuvertLe('2026-06-23 18:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $commande = noteDuService($session, $table, '2026-06-24 01:30:00', 20_000);
    $commande->statut = StatutCommandeClient::Encaissee;
    $commande->save();

    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => ModeDePaiement::factory()->create(['libelle' => 'Espèces'])->id,
        'montant' => 20_000,
    ]);

    $modes = getJson('/api/reporting/ventes/modes-paiement?periode=jour&date=2026-06-23')
        ->assertOk()->json('data.modes_paiement');

    expect((float) $modes[0]['montant_total'])->toBe(20_000.0);
});

/*
|--------------------------------------------------------------------------
| Lecture comparée
|--------------------------------------------------------------------------
*/

it('rend la période précédente et la variation quand on la demande', function () {
    $site = Site::factory()->create();
    [$juin, $tableJuin] = serviceOuvertLe('2026-06-15 12:00:00', $site);
    [$mai, $tableMai] = serviceOuvertLe('2026-05-15 12:00:00', $site);

    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($juin, $tableJuin, '2026-06-15 13:00:00', 120_000);
    noteDuService($mai, $tableMai, '2026-05-15 13:00:00', 100_000);

    $bloc = getJson('/api/reporting/ventes/points-de-vente?periode=mois&date=2026-06-15&comparer=1')
        ->assertOk()->json('data.comparaison');

    expect((float) $bloc['ca']['courant'])->toBe(120_000.0)
        ->and((float) $bloc['ca']['precedent'])->toBe(100_000.0)
        ->and((float) $bloc['ca']['variation_pct'])->toBe(20.0)
        ->and($bloc['periode_precedente']['debut'])->toStartWith('2026-05-01');
});

it('ne rend aucune comparaison tant qu\'on ne la demande pas', function () {
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    $data = getJson('/api/reporting/ventes/points-de-vente?periode=mois')->assertOk()->json('data');

    expect($data)->not->toHaveKey('comparaison');
});

/**
 * Passer de 0 à 400 000 n'est pas « +∞ % » : c'est une comparaison qui n'a pas
 * de sens, et l'écran doit afficher un tiret plutôt qu'un chiffre inventé.
 */
it('rend une variation nulle plutôt qu\'un infini quand la période d\'avant est vide', function () {
    [$session, $table] = serviceOuvertLe('2026-06-15 12:00:00');
    actingAsReportingUser(['reporting.ventes', 'reporting.global']);

    noteDuService($session, $table, '2026-06-15 13:00:00', 400_000);

    $bloc = getJson('/api/reporting/ventes/points-de-vente?periode=mois&date=2026-06-15&comparer=1')
        ->assertOk()->json('data.comparaison');

    expect((float) $bloc['ca']['precedent'])->toBe(0.0)
        ->and($bloc['ca']['variation_pct'])->toBeNull();
});
