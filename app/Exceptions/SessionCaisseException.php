<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Exception métier du module sessions de caisse.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class SessionCaisseException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function dejaOuvertePourLaCaisse(int $caisseId): self
    {
        return new self("Une session est déjà ouverte pour la caisse (id: {$caisseId}).", 409);
    }

    public static function dejaFermee(int $sessionId): self
    {
        return new self("La session de caisse (id: {$sessionId}) est déjà fermée.", 409);
    }

    /**
     * Celui qui comptera ce soir ne lit pas ce qu'il devrait trouver.
     *
     * Un montant affiché se recopie : le rapprochement ne mesurerait plus que
     * la capacité du caissier à lire un écran. La règle vaut quel que soit le
     * rôle — c'est de l'intégrité du comptage qu'il s'agit, pas de droits.
     */
    public static function theoriqueRefuseAuPorteur(int $sessionId): self
    {
        return new self(
            "Vous tenez la session de caisse (id: {$sessionId}) : son montant théorique ne peut vous être communiqué avant que le tiroir soit compté.",
            403
        );
    }

    /**
     * Un contenant n'a pas été compté.
     *
     * Tout mode de paiement accepté est vérifiable à la fermeture : on compte
     * le tiroir, on lit le ticket du terminal. Laisser passer une case vide
     * reviendrait à fermer sans avoir contrôlé, et l'écart de ce contenant ne
     * se verrait jamais.
     *
     * @param  string[]  $modes
     */
    public static function rapprochementIncomplet(array $modes): self
    {
        return new self(
            'Fermeture impossible : ces moyens de paiement n\'ont pas été comptés — '
            .implode(', ', $modes).'.'
        );
    }

    /**
     * Des notes servies attendent encore leur règlement. Les laisser derrière
     * une session fermée les rendrait orphelines : leur argent ne compterait
     * dans le théorique d'aucune caisse.
     */
    public static function notesNonReglees(int $sessionId, int $nombre): self
    {
        return new self(
            "La session de caisse (id: {$sessionId}) porte encore {$nombre} note(s) servie(s) non réglée(s) : "
            .'soldez-les ou annulez-les avec un motif avant de fermer.',
            409
        );
    }
}
