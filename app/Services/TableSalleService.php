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
     * @param  array<string, mixed>  $data
     */
    public function creer(array $data): TableSalle
    {
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
}
