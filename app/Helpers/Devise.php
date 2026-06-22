<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Formatage centralisé des montants pour les documents imprimés. La devise vient
 * toujours des paramètres d'établissement (jamais codée en dur).
 */
class Devise
{
    /**
     * Formate un montant avec l'espace comme séparateur de milliers et suffixe la
     * devise (ex. `1 500 XOF`). En l'absence de devise connue, le montant est
     * rendu seul.
     */
    public static function formater(float $montant, ?string $devise = null): string
    {
        $valeur = number_format($montant, 0, ',', ' ');

        return $devise !== null && $devise !== ''
            ? "{$valeur} {$devise}"
            : $valeur;
    }
}
