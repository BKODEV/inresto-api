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
