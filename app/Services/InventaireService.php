<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutInventaire;
use App\Exceptions\InventaireException;
use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;

/**
 * Orchestre le cycle de vie d'un inventaire : saisie des lignes (sans toucher au
 * stock) puis clôture, seul moment où des corrections de stock sont générées via
 * le StockService — sous verrou, atomiquement.
 */
class InventaireService
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * Crée un inventaire en cours. Refuse s'il en existe déjà un en cours pour
     * ce site.
     *
     * @param  array{site_id: int, date_inventaire?: string|null, notes?: string|null}  $data
     *
     * @throws InventaireException
     */
    public function creer(array $data, string $userId): Inventaire
    {
        $siteId = (int) $data['site_id'];

        if ($this->aUnInventaireEnCours($siteId)) {
            throw InventaireException::dejaEnCoursPourLeSite($siteId);
        }

        $inventaire = new Inventaire([
            'site_id' => $siteId,
            'date_inventaire' => $data['date_inventaire'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ]);
        $inventaire->user_id = $userId;
        $inventaire->statut = StatutInventaire::EnCours;
        $inventaire->save();

        return $inventaire;
    }

    /**
     * Ajoute ou met à jour une ligne (une matière par inventaire). Fige
     * `quantite_theorique` sur le stock_courant indicatif du moment.
     *
     * @param  array{matiere_premiere_id: int, quantite_physique: float|string}  $ligneData
     *
     * @throws InventaireException
     */
    public function ajouterOuModifierLigne(Inventaire $inventaire, array $ligneData, string $_userId): LigneInventaire
    {
        $this->assertEnCours($inventaire);

        $matierePremiereId = (int) $ligneData['matiere_premiere_id'];

        return DB::transaction(function () use ($inventaire, $matierePremiereId, $ligneData): LigneInventaire {
            return $inventaire->lignes()->updateOrCreate(
                ['matiere_premiere_id' => $matierePremiereId],
                [
                    'quantite_physique' => $ligneData['quantite_physique'],
                    'quantite_theorique' => $this->stockCourantIndicatif($matierePremiereId, $inventaire->site_id),
                ],
            );
        });
    }

    /**
     * Supprime une ligne — seulement tant que l'inventaire est en cours.
     *
     * @throws InventaireException
     */
    public function supprimerLigne(Inventaire $inventaire, LigneInventaire $ligne, string $_userId): void
    {
        $this->assertEnCours($inventaire);

        $ligne->delete();
    }

    /**
     * Clôture l'inventaire : génère les corrections de stock, fige les écarts et
     * la valeur totale. Irréversible et atomique (tout ou rien).
     *
     * @throws InventaireException
     */
    public function cloturer(Inventaire $inventaire, string $userId): Inventaire
    {
        $this->assertEnCours($inventaire);

        $lignes = $inventaire->lignes()->get();

        if ($lignes->isEmpty()) {
            throw InventaireException::clotureSansLigne($inventaire->id);
        }

        return DB::transaction(function () use ($inventaire, $lignes, $userId): Inventaire {
            $valeurTotale = 0.0;

            foreach ($lignes as $ligne) {
                $valeurTotale += $this->corrigerLigne($inventaire, $ligne, $userId);
            }

            $inventaire->statut = StatutInventaire::Cloture;
            $inventaire->valeur_totale_stock = round($valeurTotale, 2);
            $inventaire->user_id = $userId;
            $inventaire->save();

            return $inventaire->load(['site', 'lignes.matierePremiere']);
        });
    }

    /**
     * Corrige le stock d'une ligne et fige écart + théorique. Retourne la
     * contribution à la valeur totale (physique × pmp après correction).
     */
    private function corrigerLigne(Inventaire $inventaire, LigneInventaire $ligne, string $userId): float
    {
        $physique = (float) $ligne->quantite_physique;

        // Lecture du stock réel SOUS VERROU avant correction : aucune
        // vente/réception ne peut s'intercaler jusqu'au commit de cette transaction.
        $stockAvant = Stock::query()
            ->where('matiere_premiere_id', $ligne->matiere_premiere_id)
            ->where('site_id', $inventaire->site_id)
            ->lockForUpdate()
            ->first();

        $theoriqueReel = $stockAvant !== null ? (float) $stockAvant->stock_courant : 0.0;

        // Génère le mouvement correction_inventaire et pose stock_courant = physique
        // (firstOrCreate la ligne stocks si la matière est orpheline sur ce site).
        $this->stock->correctionInventaire(
            matierePremiereId: $ligne->matiere_premiere_id,
            siteId: $inventaire->site_id,
            quantitePhysique: $physique,
            userId: $userId,
            reference: $ligne,
        );

        $ligne->quantite_theorique = $theoriqueReel;
        $ligne->ecart = $physique - $theoriqueReel;
        $ligne->save();

        // pmp inchangé par une correction : on le relit après mise à jour.
        $pmp = (float) Stock::query()
            ->where('matiere_premiere_id', $ligne->matiere_premiere_id)
            ->where('site_id', $inventaire->site_id)
            ->value('pmp');

        return $physique * $pmp;
    }

    private function aUnInventaireEnCours(int $siteId): bool
    {
        return Inventaire::query()
            ->where('site_id', $siteId)
            ->where('statut', StatutInventaire::EnCours->value)
            ->exists();
    }

    /**
     * Stock courant indicatif (0 si la matière n'a pas encore de ligne stocks
     * sur ce site). Sert uniquement d'aide à la saisie.
     */
    private function stockCourantIndicatif(int $matierePremiereId, int $siteId): float
    {
        return (float) (Stock::query()
            ->where('matiere_premiere_id', $matierePremiereId)
            ->where('site_id', $siteId)
            ->value('stock_courant') ?? 0);
    }

    /**
     * @throws InventaireException
     */
    private function assertEnCours(Inventaire $inventaire): void
    {
        if (! $inventaire->estEnCours()) {
            throw InventaireException::ligneImmuable($inventaire->id);
        }
    }
}
