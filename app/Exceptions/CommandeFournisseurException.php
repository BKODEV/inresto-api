<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\StatutCommandeFournisseur;
use RuntimeException;

/**
 * Exception métier du module commande fournisseur (transition d'état invalide,
 * modification hors brouillon, commande sans ligne, référence inactive...).
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class CommandeFournisseurException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function transitionInvalide(StatutCommandeFournisseur $de, StatutCommandeFournisseur $vers): self
    {
        return new self("Transition impossible : une commande « {$de->value} » ne peut pas passer à « {$vers->value} ».");
    }

    public static function nonModifiable(StatutCommandeFournisseur $statut): self
    {
        return new self("La commande n'est modifiable qu'en brouillon (statut actuel : « {$statut->value} »).");
    }

    public static function sansLigne(): self
    {
        return new self('Une commande doit comporter au moins une ligne pour être envoyée.');
    }

    public static function matiereInactive(int $matierePremiereId): self
    {
        return new self("La matière première (id: {$matierePremiereId}) est introuvable ou inactive.");
    }

    public static function uniteInactive(int $uniteId): self
    {
        return new self("L'unité de mesure (id: {$uniteId}) est introuvable ou inactive.");
    }

    public static function matiereHorsCatalogue(int $matierePremiereId): self
    {
        return new self("La matière première (id: {$matierePremiereId}) n'est pas au catalogue de ce fournisseur.");
    }

    public static function annulationImpossibleAvecReception(): self
    {
        return new self('Impossible d\'annuler une commande ayant déjà au moins une réception.', 409);
    }
}
