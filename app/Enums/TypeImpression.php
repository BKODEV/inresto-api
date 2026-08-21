<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Documents d'argent remis au client, seuls journalisés.
 *
 * La réimpression d'une note est un vecteur de fraude connu ; l'impression
 * d'une fiche technique ou d'une planche QR n'en est pas un.
 *
 * Les trois se distinguent par ce qu'ils AFFIRMENT, pas par leur mise en page :
 * l'addition demande le paiement, le reçu le prouve, la facture sert la
 * comptabilité du client. Addition et reçu partagent d'ailleurs le même
 * gabarit 80mm — c'est le même papier à deux moments. Les séparer ici garde
 * le journal lisible : réimprimer une addition n'a pas le poids de réimprimer
 * un reçu.
 */
enum TypeImpression: string
{
    case Facture = 'facture';
    case Addition = 'addition';
    case Recu = 'recu';

    public function libelle(): string
    {
        return match ($this) {
            self::Facture => 'Facture',
            self::Addition => 'Addition',
            self::Recu => 'Reçu',
        };
    }
}
