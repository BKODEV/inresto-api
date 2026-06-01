<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MouvementType;
use App\Exceptions\StockException;
use App\Models\MatierePremiere;
use App\Models\MouvementStock;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pièce maîtresse du module stock.
 *
 * RÈGLE D'OR : c'est le SEUL point du code autorisé à écrire dans la table
 * `mouvements_stock` et à modifier les colonnes cache `stock_courant` et `pmp`
 * de la table `stocks`. Aucun controller ni autre service ne touche ça en
 * direct.
 *
 * MULTI-SITE : le stock est rattaché au couple (matiere_premiere_id, site_id).
 * Chaque opération porte donc un `site_id` : deux sites partagent la même
 * définition de matière première mais ont des stocks et des PMP totalement
 * indépendants.
 *
 * Hypothèses :
 * - Les quantités reçues sont DÉJÀ exprimées dans l'unité de stockage de la
 *   matière. Le service ne convertit aucune unité.
 * - `quantite` est toujours stockée positive ; le sens (entrée/sortie) vient
 *   du `type`. Le signe sur `stock_courant` est appliqué ici.
 * - Chaque opération est atomique par couple (matière, site) : transaction +
 *   verrou `lockForUpdate()` sur la ligne `stocks`, pour rester correct sous
 *   concurrence.
 */
class StockService
{
    /**
     * Entrée de stock (réception). Recalcule le PMP (prix moyen pondéré) de la
     * ligne de stock du site.
     *
     * @param  int  $matierePremiereId  Matière concernée.
     * @param  int  $siteId  Site dont on alimente le stock.
     * @param  float  $quantite  Quantité reçue (> 0, unité de stockage).
     * @param  float  $prixUnitaireReel  Prix réel à la réception (> 0, par unité de stockage).
     * @param  MouvementType  $type  Mouvement de sens « entrée » (ex. EntreeReception).
     * @param  string|null  $userId  Auteur — uuid (null si automatique).
     * @param  Model|null  $reference  Origine polymorphe (réception...).
     *
     * @throws StockException
     */
    public function entree(
        int $matierePremiereId,
        int $siteId,
        float $quantite,
        float $prixUnitaireReel,
        MouvementType $type,
        ?string $userId,
        ?Model $reference = null,
    ): MouvementStock {
        if ($quantite <= 0) {
            throw StockException::quantiteInvalide($quantite);
        }

        if ($prixUnitaireReel <= 0) {
            throw StockException::prixInvalide($prixUnitaireReel);
        }

        if ($type->sens() !== 'entree') {
            throw StockException::typeIncoherent(MouvementType::EntreeReception, $type);
        }

        return DB::transaction(function () use ($matierePremiereId, $siteId, $quantite, $prixUnitaireReel, $type, $userId, $reference): MouvementStock {
            $stock = $this->verrouiller($matierePremiereId, $siteId);

            $stockAvant = (float) $stock->stock_courant;
            $pmpAvant = (float) $stock->pmp;

            $nouveauPmp = $stockAvant <= 0
                ? $prixUnitaireReel
                : ($stockAvant * $pmpAvant + $quantite * $prixUnitaireReel) / ($stockAvant + $quantite);

            $mouvement = $this->enregistrerMouvement(
                stock: $stock,
                quantite: $quantite,
                type: $type,
                pmpAuMoment: $prixUnitaireReel,
                userId: $userId,
                reference: $reference,
            );

            $stock->stock_courant = $stockAvant + $quantite;
            $stock->pmp = $nouveauPmp;
            $stock->save();

            return $mouvement;
        });
    }

    /**
     * Sortie de stock (vente, perte, casse, offert, repas personnel) sur le
     * stock du site.
     *
     * Le PMP n'est jamais modifié par une sortie ; le mouvement enregistre le
     * PMP courant comme valorisation à l'instant T.
     *
     * @param  int  $siteId  Site dont on diminue le stock.
     * @param  float  $quantite  Quantité sortie (> 0, unité de stockage).
     * @param  MouvementType  $type  Mouvement de sens « sortie ».
     * @param  string|null  $userId  Auteur — uuid (null si automatique).
     *
     * @throws StockException
     */
    public function sortie(
        int $matierePremiereId,
        int $siteId,
        float $quantite,
        MouvementType $type,
        ?string $userId,
        ?Model $reference = null,
    ): MouvementStock {
        if ($quantite <= 0) {
            throw StockException::quantiteInvalide($quantite);
        }

        if ($type->sens() !== 'sortie') {
            throw StockException::typeIncoherent(MouvementType::SortieVente, $type);
        }

        return DB::transaction(function () use ($matierePremiereId, $siteId, $quantite, $type, $userId, $reference): MouvementStock {
            $stock = $this->verrouiller($matierePremiereId, $siteId);

            $stockApres = (float) $stock->stock_courant - $quantite;

            // Le stock peut devenir négatif si la config l'autorise. Une vente
            // n'est JAMAIS bloquée, quel que soit le réglage.
            if (
                $stockApres < 0
                && $type !== MouvementType::SortieVente
                && ! config('inresto.stock.autoriser_stock_negatif', true)
            ) {
                throw StockException::stockNegatifInterdit($stock->matiere, $quantite);
            }

            $mouvement = $this->enregistrerMouvement(
                stock: $stock,
                quantite: $quantite,
                type: $type,
                pmpAuMoment: (float) $stock->pmp,
                userId: $userId,
                reference: $reference,
            );

            $stock->stock_courant = $stockApres;
            $stock->save();

            return $mouvement;
        });
    }

    /**
     * Correction d'inventaire : aligne le stock courant du site sur la quantité
     * physique constatée. Le PMP n'est pas modifié.
     *
     * Retourne `null` si l'écart est nul (aucun mouvement créé).
     *
     * @param  int  $siteId  Site dont on corrige le stock.
     * @param  float  $quantitePhysique  Stock physiquement compté (>= 0).
     * @param  string|null  $userId  Auteur — uuid (null si automatique).
     *
     * @throws StockException
     */
    public function correctionInventaire(
        int $matierePremiereId,
        int $siteId,
        float $quantitePhysique,
        ?string $userId,
        ?Model $reference = null,
    ): ?MouvementStock {
        if ($quantitePhysique < 0) {
            throw StockException::quantitePhysiqueInvalide($quantitePhysique);
        }

        return DB::transaction(function () use ($matierePremiereId, $siteId, $quantitePhysique, $userId, $reference): ?MouvementStock {
            $stock = $this->verrouiller($matierePremiereId, $siteId);

            $ecart = $quantitePhysique - (float) $stock->stock_courant;

            // Écart négligeable (sous la précision de stockage, 3 décimales) :
            // aucun mouvement n'est créé.
            if (round($ecart, 3) === 0.0) {
                return null;
            }

            $mouvement = $this->enregistrerMouvement(
                stock: $stock,
                quantite: abs($ecart),
                type: MouvementType::CorrectionInventaire,
                pmpAuMoment: (float) $stock->pmp,
                userId: $userId,
                reference: $reference,
            );

            $stock->stock_courant = $quantitePhysique;
            $stock->save();

            return $mouvement;
        });
    }

    /**
     * Charge et verrouille la ligne `stocks` du couple (matière, site) pour la
     * durée de la transaction. Vérifie que la matière existe et est active, et
     * crée la ligne de stock (à zéro) si elle n'existe pas encore.
     *
     * @throws StockException
     */
    private function verrouiller(int $matierePremiereId, int $siteId): Stock
    {
        $matiere = MatierePremiere::query()->find($matierePremiereId);

        if ($matiere === null) {
            throw StockException::matiereIntrouvable($matierePremiereId);
        }

        if (! $matiere->isActif()) {
            throw StockException::matiereInactive($matiere);
        }

        // Crée la ligne de stock (matière, site) si absente — firstOrCreate gère
        // la collision concurrente via la contrainte d'unicité, puis on verrouille.
        Stock::query()->firstOrCreate([
            'matiere_premiere_id' => $matierePremiereId,
            'site_id' => $siteId,
        ]);

        /** @var Stock $stock */
        $stock = Stock::query()
            ->where('matiere_premiere_id', $matierePremiereId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->first();

        $stock->setRelation('matiere', $matiere);

        return $stock;
    }

    /**
     * Crée la ligne `mouvements_stock` pour la ligne de stock donnée.
     * `quantite` est toujours positive.
     */
    private function enregistrerMouvement(
        Stock $stock,
        float $quantite,
        MouvementType $type,
        float $pmpAuMoment,
        ?string $userId,
        ?Model $reference,
    ): MouvementStock {
        $mouvement = new MouvementStock([
            'matiere_premiere_id' => $stock->matiere_premiere_id,
            'site_id' => $stock->site_id,
            'user_id' => $userId,
            'type' => $type,
            'quantite' => $quantite,
            'pmp_au_moment' => $pmpAuMoment,
        ]);

        if ($reference !== null) {
            $mouvement->reference()->associate($reference);
        }

        $mouvement->save();

        return $mouvement;
    }
}
