<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutTable;
use App\Exceptions\TableSalleException;
use App\Models\TableSalle;

/**
 * Cycle de vie d'une table de salle. La suppression est protégée par un
 * garde-fou (table porteuse de commandes client) ; le changement de statut
 * manuel couvre les cas opérationnels (ex. marquer « à débarrasser »).
 */
class TableSalleService
{
    /**
     * Toute table naît avec son jeton public : le QR est imprimable dès la
     * création, sans étape supplémentaire.
     *
     * @param  array<string, mixed>  $data
     */
    public function creer(array $data): TableSalle
    {
        $data['public_token'] = TableSalle::genererPublicToken();

        return TableSalle::create($data)->load('pointDeVente');
    }

    /**
     * Crée une série de tables d'un coup, dans l'ordre reçu.
     *
     * Délègue à {@see creer()} plutôt que d'insérer en masse : c'est ce qui
     * garantit que chaque table naît avec son jeton public, et donc que la
     * planche QR du point de vente est imprimable dans la foulée.
     *
     * L'atomicité est portée par le contrôleur (transaction) : un lot à moitié
     * créé laisserait l'utilisateur deviner ce qui est passé.
     *
     * @param  array<int, array{numero: string, capacite: int}>  $lignes
     * @return array<int, int> ids des tables créées, dans l'ordre
     */
    public function creerEnLot(int $pointDeVenteId, array $lignes): array
    {
        $ids = [];

        foreach ($lignes as $ligne) {
            $ids[] = $this->creer([
                'point_de_vente_id' => $pointDeVenteId,
                ...$ligne,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifier(TableSalle $table, array $data): TableSalle
    {
        $table->update($data);

        return $table->load('pointDeVente');
    }

    /**
     * @throws TableSalleException
     */
    public function supprimer(TableSalle $table): void
    {
        if ($table->aDesCommandes()) {
            throw TableSalleException::suppressionAvecCommandes($table->id);
        }

        $table->delete();
    }

    /**
     * Retire la table du service sans toucher à son historique : elle sort du
     * plan de salle, du sélecteur de caisse et de la planche QR, et son jeton
     * public cesse de servir un menu. Réversible via {@see reactiver()}.
     *
     * Refusé tant qu'une commande vit encore sur la table : la faire disparaître
     * du plan en plein service laisserait le serveur sans écran de reprise.
     *
     * @throws TableSalleException
     */
    public function archiver(TableSalle $table): TableSalle
    {
        if ($table->estArchivee()) {
            throw TableSalleException::dejaArchivee($table->id);
        }

        if ($table->aUneCommandeEnCours()) {
            throw TableSalleException::archivageAvecCommandeEnCours($table->id);
        }

        $table->archivee_le = now();
        $table->save();

        return $table->load('pointDeVente');
    }

    /**
     * Remet la table en service. Son jeton public est inchangé : les QR déjà
     * imprimés refonctionnent tels quels, ce qui est exactement l'intérêt
     * d'archiver plutôt que de supprimer et recréer.
     *
     * @throws TableSalleException
     */
    public function reactiver(TableSalle $table): TableSalle
    {
        if (! $table->estArchivee()) {
            throw TableSalleException::nonArchivee($table->id);
        }

        $table->archivee_le = null;
        $table->save();

        return $table->load('pointDeVente');
    }

    public function changerStatut(TableSalle $table, StatutTable $statut): TableSalle
    {
        $table->statut = $statut;
        $table->save();

        return $table->load('pointDeVente');
    }

    /**
     * Remplace le jeton public : l'ancien lien et les QR déjà imprimés cessent
     * immédiatement de fonctionner. À utiliser si un QR a fuité ou a été altéré.
     */
    public function regenererToken(TableSalle $table): TableSalle
    {
        $table->public_token = TableSalle::genererPublicToken();
        $table->save();

        return $table->load('pointDeVente');
    }
}
