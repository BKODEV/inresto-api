<?php

declare(strict_types=1);

namespace App\Enums;

enum MouvementType: string
{
    case EntreeReception = 'entree_reception';
    case SortieVente = 'sortie_vente';
    case Perte = 'perte';
    case Casse = 'casse';
    case Offert = 'offert';
    case RepasPersonnel = 'repas_personnel';
    case CorrectionInventaire = 'correction_inventaire';

    /**
     * Sens du mouvement par rapport au stock courant.
     *
     * - `entree`  : ajoute au stock (réceptions).
     * - `sortie`  : retire du stock (ventes, pertes, casses, offerts, repas personnel).
     * - `neutre`  : ne suit pas la logique +/- (correction d'inventaire qui pose une valeur absolue).
     *
     * @return 'entree'|'sortie'|'neutre'
     */
    public function sens(): string
    {
        return match ($this) {
            self::EntreeReception => 'entree',
            self::SortieVente,
            self::Perte,
            self::Casse,
            self::Offert,
            self::RepasPersonnel => 'sortie',
            self::CorrectionInventaire => 'neutre',
        };
    }
}
