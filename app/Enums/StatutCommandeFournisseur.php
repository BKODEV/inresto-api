<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Machine à états d'une commande fournisseur.
 *
 *   brouillon ──> envoyee ──> partiellement_recue ──> cloturee
 *       │            │                 │
 *       └────────────┴─────────────────┴──> annulee   (sauf depuis cloturee)
 *
 * Les états `partiellement_recue` et `cloturee` sont pilotés par le module
 * réception (étape 3), pas par le module commande.
 */
enum StatutCommandeFournisseur: string
{
    case Brouillon = 'brouillon';
    case Envoyee = 'envoyee';
    case PartiellementRecue = 'partiellement_recue';
    case Cloturee = 'cloturee';
    case Annulee = 'annulee';

    /**
     * États atteignables depuis l'état courant.
     *
     * @return self[]
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Envoyee, self::Annulee],
            self::Envoyee => [self::PartiellementRecue, self::Cloturee, self::Annulee],
            self::PartiellementRecue => [self::Cloturee, self::Annulee],
            self::Cloturee, self::Annulee => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    /**
     * La commande n'est modifiable (lignes, quantités, prix, entête) qu'en
     * brouillon. Tout autre état la verrouille en écriture.
     */
    public function estModifiable(): bool
    {
        return $this === self::Brouillon;
    }
}
