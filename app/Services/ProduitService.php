<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProduitException;
use App\Models\Produit;

/**
 * Cycle de vie d'un produit (définition partagée). La suppression est un soft
 * delete protégé : un produit référencé par une fiche technique active ne peut
 * être supprimé.
 */
class ProduitService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function creer(array $data): Produit
    {
        return Produit::create($data)->load('categorie');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function modifier(Produit $produit, array $data): Produit
    {
        $produit->update($data);

        return $produit->load('categorie');
    }

    /**
     * Soft delete avec garde-fou fiche active.
     *
     * @throws ProduitException
     */
    public function supprimer(Produit $produit): void
    {
        if ($produit->estReferenceeDansFicheActive()) {
            throw ProduitException::suppressionAvecFicheActive($produit->id);
        }

        $produit->delete();
    }
}
