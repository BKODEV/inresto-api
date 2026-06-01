<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\MouvementType;
use App\Models\MatierePremiere;
use RuntimeException;

/**
 * Exception métier levée par le StockService lorsqu'une opération de stock
 * est invalide (quantité/prix non positifs, matière introuvable ou inactive,
 * stock négatif interdit par configuration...).
 */
class StockException extends RuntimeException
{
    public static function matiereIntrouvable(int $matierePremiereId): self
    {
        return new self("Matière première introuvable (id: {$matierePremiereId}).");
    }

    public static function matiereInactive(MatierePremiere $matiere): self
    {
        return new self("La matière première « {$matiere->libelle} » (id: {$matiere->id}) est inactive.");
    }

    public static function quantiteInvalide(float $quantite): self
    {
        return new self("La quantité doit être strictement positive, reçu : {$quantite}.");
    }

    public static function prixInvalide(float $prix): self
    {
        return new self("Le prix unitaire réel doit être strictement positif, reçu : {$prix}.");
    }

    public static function quantitePhysiqueInvalide(float $quantitePhysique): self
    {
        return new self("La quantité physique d'inventaire ne peut pas être négative, reçu : {$quantitePhysique}.");
    }

    public static function typeIncoherent(MouvementType $attendu, MouvementType $recu): self
    {
        return new self("Type de mouvement incohérent : attendu un mouvement de sens « {$attendu->sens()} », reçu « {$recu->value} » (sens « {$recu->sens()} »).");
    }

    public static function stockNegatifInterdit(MatierePremiere $matiere, float $quantite): self
    {
        return new self("Sortie de {$quantite} refusée : le stock de « {$matiere->libelle} » (id: {$matiere->id}) deviendrait négatif et le stock négatif est désactivé.");
    }
}
