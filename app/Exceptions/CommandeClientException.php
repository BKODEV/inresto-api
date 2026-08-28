<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\StatutCommandeClient;
use RuntimeException;

/**
 * Exception métier du module commande client.
 *
 * Porte un code HTTP exploité par le handler global (bootstrap/app.php).
 */
class CommandeClientException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function tableIndisponible(int $tableId): self
    {
        return new self("La table (id: {$tableId}) doit être libre ou réservée pour ouvrir une commande.", 409);
    }

    public static function noteClose(int $commandeId): self
    {
        return new self("La note (id: {$commandeId}) est close : elle ne peut plus être modifiée.", 409);
    }

    public static function serviceSansLigne(int $commandeId): self
    {
        return new self("Aucune ligne en attente de service sur la note (id: {$commandeId}).", 409);
    }

    public static function annonceSansLigne(int $commandeId): self
    {
        return new self("Aucune ligne en production à annoncer prête sur la note (id: {$commandeId}).", 409);
    }

    /**
     * Dévalider ne rattrape que ce qui est encore au pass : une ligne servie est
     * partie en salle, et le stock a bougé avec elle.
     */
    public static function devalidationSansLigne(int $commandeId): self
    {
        return new self("Aucune ligne prête et non servie à dévalider sur la note (id: {$commandeId}).", 409);
    }

    public static function tableArchivee(int $tableId): self
    {
        return new self("La table (id: {$tableId}) est archivée : réactivez-la pour y ouvrir une commande.", 409);
    }

    public static function liberationImpossible(int $commandeId): self
    {
        return new self("La commande (id: {$commandeId}) a déjà porté des lignes ou des paiements : elle doit être annulée avec un motif, pas supprimée.", 409);
    }

    public static function commandeActiveExistante(int $tableId): self
    {
        return new self("Une commande est déjà en cours sur la table (id: {$tableId}).", 409);
    }

    public static function sessionFermee(int $sessionId): self
    {
        return new self("La session de caisse (id: {$sessionId}) n'est pas ouverte.");
    }

    public static function sessionAutrePointDeVente(): self
    {
        return new self('La session de caisse n\'appartient pas au même point de vente que la table.');
    }

    public static function accesRefuse(): self
    {
        return new self('Vous n\'êtes pas autorisé à utiliser une session de caisse sur ce site.', 403);
    }

    public static function ajoutLigneStatutInvalide(int $commandeId): self
    {
        return new self("Impossible d'ajouter une ligne : la commande (id: {$commandeId}) n'est pas ouverte.");
    }

    public static function produitHorsCarte(int $produitId): self
    {
        return new self("Le produit (id: {$produitId}) ne figure pas sur la carte active de ce point de vente.");
    }

    public static function envoiSansLigne(int $commandeId): self
    {
        return new self("Impossible d'envoyer en cuisine la commande (id: {$commandeId}) : aucune ligne active.");
    }

    public static function transitionInvalide(StatutCommandeClient $de, StatutCommandeClient $vers): self
    {
        return new self("Transition de commande invalide : {$de->value} → {$vers->value}.", 409);
    }

    public static function resteAPayer(float $reste): self
    {
        return new self("Encaissement impossible : il reste {$reste} à payer.");
    }
}
