<?php

declare(strict_types=1);

use App\Enums\TypeImpression;
use App\Models\Caisse;
use App\Models\CatalogueFournisseur;
use App\Models\CommandeClient;
use App\Models\CommandeFournisseur;
use App\Models\FicheTechnique;
use App\Models\Impression;
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
use App\Services\SessionCaisseService;
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

it('génère l\'addition en PDF', function () {
    actingWithPdfPerms(['commande_client.view']);
    $commande = pdfCommandeClient();

    getJson("/api/commandes-client/{$commande->id}/addition")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

/**
 * Sur une quantité multiple, le total de la ligne ne se vérifie pas : le client
 * voit « 2 × Café  2 000 » sans savoir ce que vaut un café. Le prix unitaire
 * n'était imprimé que sur la facture A4, que personne ne remet au comptoir.
 */
it('imprime le prix unitaire sous une ligne à quantité multiple', function () {
    // La commande de référence porte 2 cafés à 1 000.
    $commande = pdfCommandeClient();

    $html = app(PdfService::class)->htmlTicketClient($commande, TypeImpression::Addition);

    expect($html)
        ->toContain('2 × Café')
        ->toContain('P.U. 1 000 XOF');
});

/**
 * Sur une quantité de 1, le prix unitaire répète le total de la ligne : deux
 * fois le même chiffre sur 72 mm de large fait douter d'un double comptage.
 */
it('tait le prix unitaire sur une ligne à quantité 1', function () {
    $commande = pdfCommandeClient();
    $commande->lignes()->update(['quantite' => 1]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('lignes'), TypeImpression::Addition);

    expect($html)
        ->toContain('1 × Café')
        ->not->toContain('P.U.');
});

/**
 * Addition et reçu partagent un gabarit, ce qui rend facile de laisser fuiter
 * l'un dans l'autre. Le ticket présenté AVANT le paiement ne doit rien affirmer
 * sur l'argent : ni ligne de paiement, ni monnaie, ni remerciement pour une
 * visite qui n'est pas réglée.
 */
it('n\'affiche aucun paiement ni remerciement sur l\'addition', function () {
    $commande = pdfCommandeClient();

    $html = app(PdfService::class)->htmlTicketClient($commande, TypeImpression::Addition);

    expect($html)
        ->toContain('ADDITION')
        ->toContain('TOTAL À PAYER')
        ->not->toContain('Merci de votre visite')
        ->not->toContain('MONNAIE');
});

it('imprime le montant tendu et la monnaie sur le reçu', function () {
    $commande = pdfCommandeClient();
    // Le client pose 5 000 pour une note de 1 500 : 3 500 à lui rendre.
    $commande->paiements()->update(['montant' => 1500, 'montant_recu' => 5000]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    expect($html)
        ->toContain('REÇU')
        ->toContain('MONNAIE')
        ->toContain('5 000 XOF')   // tendu
        ->toContain('3 500 XOF')   // monnaie rendue
        ->toContain('Merci de votre visite');
});

/**
 * Un pourboire est de l'argent que le client a LAISSÉ : le compter dans la
 * monnaie annoncerait au client un rendu qu'il ne recevra pas.
 */
it('retranche le pourboire de la monnaie annoncée au client', function () {
    $commande = pdfCommandeClient();
    // Note de 1 500 : le client pose 5 000, laisse 500, on lui rend 3 000.
    $commande->paiements()->update(['montant' => 1500, 'pourboire' => 500, 'montant_recu' => 5000]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    expect($html)
        ->toContain('Dont pourboire')
        ->toContain('500 XOF')
        ->toContain('3 000 XOF')      // monnaie réellement rendue
        ->not->toContain('3 500 XOF'); // ce qu'on annonçait sans le pourboire
});

/**
 * `montant` alimente `montant_theorique` de la session : y inscrire le billet
 * tendu ferait apparaître un manquant chaque soir, égal à la monnaie rendue.
 * Le reçu doit donc lire `montant_recu`, jamais gonfler `montant`.
 *
 * Sans billet tendu — une carte — il n'y a ni reçu ni monnaie à annoncer.
 */
it('n\'annonce ni tendu ni monnaie sans billet sur le comptoir', function () {
    $commande = pdfCommandeClient();
    $commande->paiements()->update(['montant' => 1500, 'montant_recu' => null]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    expect($html)
        ->not->toContain('MONNAIE')
        ->not->toContain('<td>Reçu</td>');
});

/**
 * Le compte juste EST un geste : le client a posé 1 500 sur une note de 1 500.
 * Taire le reçu et la monnaie laissait le ticket muet sur ce qu'il venait de
 * faire, et indiscernable d'un règlement par carte.
 */
it('annonce le tendu et une monnaie nulle sur un appoint juste en espèces', function () {
    $commande = pdfCommandeClient();
    $commande->paiements()->update(['montant' => 1500, 'montant_recu' => 1500]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    expect($html)
        ->toContain('<td>Reçu</td>')
        ->toContain('MONNAIE')
        ->toContain('0 XOF');
});

/**
 * Un acompte en espèces ne rend rien : la monnaie serait négative. Annoncer
 * « MONNAIE -1 000 » ferait lire au client un rendu à l'envers, alors que la
 * ligne « Reste » dit déjà ce qu'il doit encore.
 */
it('n\'annonce pas de monnaie négative sur un paiement partiel en espèces', function () {
    $commande = pdfCommandeClient();
    // 500 posés sur une note de 1 500 : il reste 1 000 à payer.
    $commande->paiements()->update(['montant' => 500, 'montant_recu' => 500]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    expect($html)
        ->toContain('<td>Reçu</td>')
        ->not->toContain('MONNAIE')
        ->toContain('Reste');
});

/**
 * Les paiements antérieurs à `montant_recu` portaient la monnaie dans un
 * surpaiement. Le reçu réimprimé doit rester juste.
 */
it('lit la monnaie d\'un surpaiement enregistré avant montant_recu', function () {
    $commande = pdfCommandeClient();
    $commande->paiements()->update(['montant' => 2000, 'montant_recu' => null]);

    $html = app(PdfService::class)->htmlTicketClient($commande->fresh('paiements'));

    // Note de 1 500 payée 2 000 : 500 de monnaie, et aucun reste à réclamer.
    expect($html)
        ->toContain('MONNAIE')
        ->toContain('500 XOF')
        ->not->toContain('Reste');
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

// ───────────────────────── rapport de fermeture ─────────────────────────

/**
 * Session fermée, rattachée à un point de vente pourvu d'une fiche
 * d'établissement — sans ce lien, `parametresPourSite()` renverrait null et le
 * ticket afficherait le bandeau « non configuré » au lieu du rapport.
 */
function sessionFermeeAvecVentes(): SessionCaisse
{
    $site = Site::factory()->create();
    ParametreEtablissement::factory()->create([
        'site_id' => $site->id,
        'devise' => 'XOF',
        'tolerance_ecart_caisse' => 500,
    ]);
    $pdv = PointDeVente::factory()->create(['site_id' => $site->id]);
    $caisse = Caisse::factory()->create(['point_de_vente_id' => $pdv->id]);
    $table = TableSalle::factory()->create(['point_de_vente_id' => $pdv->id]);
    $serveur = User::factory()->create(['prenom' => 'Kouassi', 'nom' => 'Ake']);

    $session = SessionCaisse::factory()->create([
        'caisse_id' => $caisse->id,
        'fonds_initial' => 10000,
    ]);

    $commande = CommandeClient::factory()->create([
        'table_id' => $table->id,
        'session_caisse_id' => $session->id,
        'user_id' => $serveur->id,
        'statut' => 'encaissee',
    ]);
    LigneCommandeClient::factory()->create([
        'commande_client_id' => $commande->id,
        'produit_id' => Produit::factory()->create(['libelle' => 'Attiéké'])->id,
        'quantite' => 2,
        'prix_unitaire' => 3000,
    ]);

    $especes = ModeDePaiement::factory()->create(['libelle' => 'Espèces']);
    $virement = ModeDePaiement::factory()->horsTiroir()->create(['libelle' => 'Virement']);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $especes->id,
        'montant' => 6000,
        'pourboire' => 1000,
    ]);
    Paiement::factory()->create([
        'commande_client_id' => $commande->id,
        'mode_de_paiement_id' => $virement->id,
        'montant' => 4000,
        'pourboire' => 0,
    ]);

    // Fonds 10 000 + 6 000 d'espèces + 1 000 de pourboire = 17 000 attendus dans
    // le tiroir ; le virement tombe pile. Chaque contenant doit être compté,
    // la fermeture le refuserait sinon.
    return app(SessionCaisseService::class)->fermer($session, ['rapprochements' => [
        ['mode_de_paiement_id' => $especes->id, 'montant_constate' => 17000],
        ['mode_de_paiement_id' => $virement->id, 'montant_constate' => 4000],
    ]]);
}

it('génère le rapport de fermeture en PDF', function () {
    $user = actingWithPdfPerms(['session_caisse.view']);
    $session = sessionFermeeAvecVentes();
    // Un rapport Z porte l'écart et le motif de forçage d'un tiroir : il se lit
    // désormais dans le périmètre du site, comme la session elle-même.
    $user->sites()->syncWithoutDetaching([$session->caisse->pointDeVente->site_id]);

    getJson("/api/sessions-caisse/{$session->id}/rapport-fermeture")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('rapproche chaque contenant et isole le chiffre d\'affaires', function () {
    $session = sessionFermeeAvecVentes();

    $html = app(PdfService::class)->htmlRapportFermeture($session);

    expect($html)
        ->toContain('RAPPORT DE FERMETURE')
        ->toContain('RAPPROCHEMENT')
        // CA = les lignes (2 × 3 000), ni le pourboire ni le mode n'y entrent.
        ->toContain('6 000 XOF')
        // Théorique du tiroir = fonds + espèces + pourboire, et il tombe juste.
        ->toContain('17 000 XOF')
        ->toContain('ÉCART DE CAISSE')
        ->toContain('Caisse juste');
});

/**
 * Le rapport distingue ce qui dort dans le tiroir de ce qui n'y passe jamais :
 * c'est ce qui explique qu'un tiroir presque vide puisse être juste.
 */
it('signale les contenants qui ne passent pas par le tiroir', function () {
    $session = sessionFermeeAvecVentes();

    $html = app(PdfService::class)->htmlRapportFermeture($session);

    expect($html)
        ->toContain('Virement')
        ->toContain('hors tiroir')
        ->toContain('4 000 XOF');
});

it('liste les pourboires à reverser, par serveur', function () {
    $session = sessionFermeeAvecVentes();

    $html = app(PdfService::class)->htmlRapportFermeture($session);

    expect($html)
        ->toContain('POURBOIRES À REVERSER')
        ->toContain('Kouassi Ake')
        ->toContain('1 000 XOF');
});

/**
 * `user_id` ne désigne que l'ouverture : une session peut traverser une relève.
 * C'est le geste de fermeture qui fige l'écart, donc lui qu'il faut signer.
 */
it('porte les deux signatures, ouverture et fermeture', function () {
    $session = sessionFermeeAvecVentes();

    $html = app(PdfService::class)->htmlRapportFermeture($session);

    expect($html)
        ->toContain('Ouverte par')
        ->toContain('Fermée par');
});

it('journalise l\'impression du rapport de fermeture', function () {
    $user = actingWithPdfPerms(['session_caisse.view']);
    $session = sessionFermeeAvecVentes();
    $user->sites()->syncWithoutDetaching([$session->caisse->pointDeVente->site_id]);

    getJson("/api/sessions-caisse/{$session->id}/rapport-fermeture")->assertOk();

    $impression = Impression::query()->where('type', 'rapport_fermeture')->first();

    expect($impression)->not->toBeNull()
        ->and($impression->imprimable_id)->toBe($session->id)
        ->and($impression->user_id)->toBe($user->id);
});

it('refuse le rapport de fermeture sans la permission de lecture', function () {
    actingWithPdfPerms([]);
    $session = sessionFermeeAvecVentes();

    getJson("/api/sessions-caisse/{$session->id}/rapport-fermeture")->assertForbidden();
});

it('ne contient aucune donnée d\'établissement codée en dur (revente)', function () {
    $fichiers = glob(resource_path('views/pdf/**/*.blade.php'));
    $fichiers = array_merge($fichiers, glob(resource_path('views/pdf/*.blade.php')));

    foreach ($fichiers as $fichier) {
        expect(file_get_contents($fichier))->not->toContain('Innov');
    }
});
