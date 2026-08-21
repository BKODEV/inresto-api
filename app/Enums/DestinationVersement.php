<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Où part l'argent sorti du tiroir.
 *
 * Liste fermée plutôt que texte libre : une saisie ouverte donnerait « coffre »,
 * « Coffre » et « au coffre » au bout d'un mois, et plus rien d'interrogeable.
 *
 * `Personnel` est la seule destination qui diminue un SOLDE : reverser un
 * pourboire éteint une dette envers un serveur précis, ce qui exige de savoir
 * lequel (`beneficiaire_id`). Les autres destinations n'ont pas de solde — une
 * société de collecte ou un coffre ne doivent rien à personne — et se
 * contentent de `remis_a`, qui dit qui a emporté l'argent.
 */
enum DestinationVersement: string
{
    case Coffre = 'coffre';
    case Banque = 'banque';
    case Direction = 'direction';
    case Personnel = 'personnel';
    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Coffre => 'Coffre',
            self::Banque => 'Banque',
            self::Direction => 'Direction',
            self::Personnel => 'Personnel (pourboires)',
            self::Autre => 'Autre',
        };
    }

    /** Cette sortie éteint-elle une dette envers quelqu'un de nommé ? */
    public function exigeUnBeneficiaire(): bool
    {
        return $this === self::Personnel;
    }
}
