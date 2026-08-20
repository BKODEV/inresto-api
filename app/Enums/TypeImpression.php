<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Documents d'argent remis au client, seuls journalisés.
 *
 * La réimpression d'une note est un vecteur de fraude connu ; l'impression
 * d'une fiche technique ou d'une planche QR n'en est pas un.
 */
enum TypeImpression: string
{
    case Facture = 'facture';
    case Recu = 'recu';

    public function libelle(): string
    {
        return match ($this) {
            self::Facture => 'Facture',
            self::Recu => 'Reçu',
        };
    }
}
