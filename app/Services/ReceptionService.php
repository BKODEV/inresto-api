<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MouvementType;
use App\Enums\StatutCommandeFournisseur;
use App\Exceptions\ReceptionException;
use App\Models\Budget;
use App\Models\CatalogueFournisseur;
use App\Models\CommandeFournisseur;
use App\Models\Depense;
use App\Models\LigneCommandeFournisseur;
use App\Models\LigneReception;
use App\Models\Reception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre la confirmation d'une réception : conversion unité d'achat ->
 * unité de stockage, entrée de stock (via StockService), imputation budgétaire
 * (dépense par ligne) et synchronisation du statut de la commande liée.
 *
 * RÈGLE : la réception est le SEUL point du système qui déclenche une entrée de
 * stock. Toute la confirmation est transactionnelle (tout ou rien) : si le
 * StockService lève, si le budget du site est absent ou si un facteur de
 * conversion manque, RIEN n'est créé (ni stock, ni dépense, ni ligne).
 */
class ReceptionService
{
    /**
     * Tolérance d'écart (1 %) en deçà de laquelle une ligne de commande est
     * considérée intégralement reçue (arrondis de pesée, sur-livraison).
     */
    private const TOLERANCE = 0.01;

    /**
     * Statuts de commande autorisant une réception.
     *
     * @var StatutCommandeFournisseur[]
     */
    private const STATUTS_RECEVABLES = [
        StatutCommandeFournisseur::Envoyee,
        StatutCommandeFournisseur::PartiellementRecue,
    ];

    public function __construct(private readonly StockService $stock) {}

    /**
     * Confirme une réception : crée ses lignes, alimente le stock du site,
     * impute la dépense au budget du site, calcule le montant réel et met à
     * jour le statut de la commande.
     *
     * @param  array<int, array{matiere_premiere_id: int, unite_id: int, quantite_recue: float|string, prix_unitaire_reel: float|string}>  $lignes
     *
     * @throws ReceptionException
     */
    public function confirmer(Reception $reception, array $lignes, string $userId): Reception
    {
        $commande = $reception->commande;

        if (! in_array($commande->statut, self::STATUTS_RECEVABLES, true)) {
            throw ReceptionException::statutCommandeInvalide($commande->statut);
        }

        if ($reception->lignes()->exists()) {
            throw ReceptionException::dejaConfirmee($reception->id);
        }

        return DB::transaction(function () use ($reception, $lignes, $userId, $commande): Reception {
            $budget = $this->budgetDuSite($reception);
            $reception->user_id = $userId;
            $montantReel = 0.0;

            foreach ($lignes as $donnees) {
                $montantReel += $this->traiterLigne($reception, $budget, $donnees, $userId);
            }

            $reception->montant_reel = $montantReel;
            $reception->save();

            $this->synchroniserStatutCommande($commande);

            return $reception->load(['commande', 'site', 'lignes.matierePremiere', 'lignes.unite']);
        });
    }

    /**
     * Traite une ligne reçue : conversion, entrée de stock, dépense. Retourne le
     * montant réel facturé de la ligne (quantité reçue × prix unitaire réel).
     *
     * @param  array{matiere_premiere_id: int, unite_id: int, quantite_recue: float|string, prix_unitaire_reel: float|string}  $donnees
     *
     * @throws ReceptionException
     */
    private function traiterLigne(Reception $reception, Budget $budget, array $donnees, string $userId): float
    {
        $matierePremiereId = (int) $donnees['matiere_premiere_id'];
        $quantiteRecue = (float) $donnees['quantite_recue'];
        $prixUnitaireReel = (float) $donnees['prix_unitaire_reel'];

        $facteur = $this->facteurConversion($reception->commande->fournisseur_id, $matierePremiereId);

        // Seule conversion du système : unité d'achat -> unité de stockage.
        $quantiteStockage = $quantiteRecue * $facteur;
        $prixStockage = $prixUnitaireReel / $facteur;

        $ligne = $reception->lignes()->create([
            'matiere_premiere_id' => $matierePremiereId,
            'unite_id' => (int) $donnees['unite_id'],
            'quantite_recue' => $quantiteRecue,
            'prix_unitaire_reel' => $prixUnitaireReel,
        ]);

        $this->stock->entree(
            matierePremiereId: $matierePremiereId,
            siteId: $reception->site_id,
            quantite: $quantiteStockage,
            prixUnitaireReel: $prixStockage,
            type: MouvementType::EntreeReception,
            userId: $userId,
            reference: $ligne,
        );

        $montantLigne = round($quantiteRecue * $prixUnitaireReel, 2);

        Depense::create([
            'budget_id' => $budget->id,
            'reception_id' => $reception->id,
            'type' => 'achat_fournisseur',
            'libelle' => "Réception #{$reception->id} — matière #{$matierePremiereId}",
            'montant' => $montantLigne,
            'date_depense' => $reception->date_reception->toDateString(),
        ]);

        return $montantLigne;
    }

    /**
     * Facteur `qte_par_unite_achat` du couple (fournisseur de la commande,
     * matière). Absent => le catalogue doit être renseigné d'abord.
     *
     * @throws ReceptionException
     */
    private function facteurConversion(int $fournisseurId, int $matierePremiereId): float
    {
        $facteur = CatalogueFournisseur::query()
            ->where('fournisseur_id', $fournisseurId)
            ->where('matiere_premiere_id', $matierePremiereId)
            ->value('qte_par_unite_achat');

        if ($facteur === null) {
            throw ReceptionException::facteurConversionManquant($fournisseurId, $matierePremiereId);
        }

        return (float) $facteur;
    }

    /**
     * Budget du site de la réception pour le mois de la réception. Absent => la
     * transaction entière est annulée (pas de dépense orpheline ni d'entrée
     * stock partielle).
     *
     * @throws ReceptionException
     */
    private function budgetDuSite(Reception $reception): Budget
    {
        $mois = Carbon::parse($reception->date_reception)->startOfMonth();

        $budget = Budget::query()
            ->where('site_id', $reception->site_id)
            ->whereDate('mois', $mois->toDateString())
            ->first();

        if ($budget === null) {
            throw ReceptionException::budgetSiteAbsent($reception->site_id, $mois->format('Y-m'));
        }

        return $budget;
    }

    /**
     * Compare le cumul reçu (toutes réceptions confondues, en unité d'achat) aux
     * quantités commandées et fait transiter la commande : intégral (à 1 % près)
     * => cloturee, sinon => partiellement_recue.
     *
     * @throws ReceptionException
     */
    private function synchroniserStatutCommande(CommandeFournisseur $commande): void
    {
        $receptionIds = $commande->receptions()->pluck('id')->all();

        $recuParMatiere = LigneReception::query()
            ->whereIn('reception_id', $receptionIds)
            ->selectRaw('matiere_premiere_id, SUM(quantite_recue) AS total')
            ->groupBy('matiere_premiere_id')
            ->pluck('total', 'matiere_premiere_id');

        $integrale = $commande->lignes->every(function (LigneCommandeFournisseur $ligne) use ($recuParMatiere): bool {
            $commandee = (float) $ligne->quantite_commandee;
            $recu = (float) ($recuParMatiere[$ligne->matiere_premiere_id] ?? 0);

            return $recu >= $commandee * (1 - self::TOLERANCE);
        });

        $commandeService = app(CommandeFournisseurService::class);

        if ($integrale) {
            if ($commande->statut !== StatutCommandeFournisseur::Cloturee) {
                $commandeService->marquerCloturee($commande);
            }

            return;
        }

        // Réception partielle : ne re-transitionne que depuis « envoyee »
        // (partiellement_recue -> partiellement_recue serait une transition
        // invalide et n'apporte rien).
        if ($commande->statut === StatutCommandeFournisseur::Envoyee) {
            $commandeService->marquerPartiellementRecue($commande);
        }
    }
}
