<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce qu'un bon demande au poste.
 *
 * `Ajout` n'est pas un `Envoi` comme un autre : la table mange déjà. Le poste
 * qui le reçoit doit le comprendre d'un coup d'œil, sans relire la note.
 * `Annulation` demande d'arrêter ce qui a été lancé.
 */
enum TypeBonCuisine: string
{
    case Envoi = 'envoi';
    case Ajout = 'ajout';
    case Annulation = 'annulation';

    public function libelle(): string
    {
        return match ($this) {
            self::Envoi => 'Envoi',
            self::Ajout => 'Ajout',
            self::Annulation => 'Annulation',
        };
    }
}
