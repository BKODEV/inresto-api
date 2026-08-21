<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Où part l'argent sorti du tiroir.
 *
 * Liste fermée plutôt que texte libre : une saisie ouverte donnerait « coffre »,
 * « Coffre » et « au coffre » au bout d'un mois, et plus rien d'interrogeable.
 * Quatre valeurs couvrent la réalité d'un restaurant ; le détail de qui a reçu
 * l'argent vit dans `remis_a`.
 */
enum DestinationVersement: string
{
    case Coffre = 'coffre';
    case Banque = 'banque';
    case Direction = 'direction';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Coffre => 'Coffre',
            self::Banque => 'Banque',
            self::Direction => 'Direction',
            self::Autre => 'Autre',
        };
    }
}
