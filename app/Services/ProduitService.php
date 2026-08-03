<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ProduitException;
use App\Models\Produit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cycle de vie d'un produit (définition partagée). La suppression est un soft
 * delete protégé : un produit référencé par une fiche technique active ne peut
 * être supprimé.
 */
class ProduitService
{
    private const DISQUE_PHOTO = 'public';

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

    /**
     * Enregistre (ou écrase) la photo du produit, celle que voit le client sur
     * le menu public.
     *
     * L'ancien fichier est effacé d'abord : l'extension varie selon le format
     * envoyé, si bien qu'écraser sans supprimer laisserait des orphelins sur le
     * disque (un `photo.jpg` remplacé par un `photo.webp`).
     */
    public function enregistrerPhoto(Produit $produit, UploadedFile $photo): Produit
    {
        $this->supprimerFichierPhoto($produit);

        $produit->photo = $photo->storeAs(
            "produits/{$produit->id}",
            'photo.'.$photo->getClientOriginalExtension(),
            self::DISQUE_PHOTO,
        );
        $produit->save();

        return $produit->load('categorie');
    }

    /**
     * Retire la photo : efface le fichier et remet `photo` à null.
     */
    public function supprimerPhoto(Produit $produit): Produit
    {
        $this->supprimerFichierPhoto($produit);

        $produit->photo = null;
        $produit->save();

        return $produit->load('categorie');
    }

    /**
     * Le champ peut porter une URL absolue héritée : dans ce cas il n'y a aucun
     * fichier local à effacer.
     */
    private function supprimerFichierPhoto(Produit $produit): void
    {
        $chemin = $produit->photo;

        if ($chemin === null || Str::startsWith($chemin, ['http://', 'https://'])) {
            return;
        }

        Storage::disk(self::DISQUE_PHOTO)->delete($chemin);
    }
}
