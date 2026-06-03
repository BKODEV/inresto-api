<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CarteStatut;
use App\Exceptions\CarteProduitException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarteProduit\StoreCarteProduitRequest;
use App\Http\Requests\CarteProduit\UpdateCarteProduitRequest;
use App\Http\Resources\CarteProduitResource;
use App\Models\Carte;
use App\Models\CarteProduit;
use Illuminate\Http\JsonResponse;

class CarteProduitController extends Controller
{
    /**
     * Liste les produits d'une carte (lignes carte_produit + produit + prix).
     */
    public function index(Carte $carte): JsonResponse
    {
        $this->authorize('viewAny', CarteProduit::class);

        $lignes = CarteProduit::query()
            ->where('carte_id', $carte->id)
            ->with('produit')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            ['produits' => CarteProduitResource::collection($lignes)],
            'Produits de la carte récupérés.'
        );
    }

    /**
     * Ajoute un produit (actif) à la carte. La carte n'a pas besoin d'être active.
     */
    public function store(StoreCarteProduitRequest $request, Carte $carte): JsonResponse
    {
        $ligne = CarteProduit::create([
            'carte_id' => $carte->id,
            ...$request->validated(),
        ]);

        return ApiResponse::created(
            new CarteProduitResource($ligne->load('produit')),
            'Produit ajouté à la carte.'
        );
    }

    /**
     * Modifie le prix de vente ou le statut d'une ligne.
     */
    public function update(UpdateCarteProduitRequest $request, Carte $carte, CarteProduit $carte_produit): JsonResponse
    {
        $carte_produit->update($request->validated());

        return ApiResponse::updated(
            new CarteProduitResource($carte_produit->load('produit')),
            'Ligne de carte mise à jour.'
        );
    }

    /**
     * Retire un produit de la carte. Interdit (409) si la carte est active :
     * on désactive la ligne (PATCH statut) plutôt que de supprimer.
     */
    public function destroy(Carte $carte, CarteProduit $carte_produit): JsonResponse
    {
        $this->authorize('delete', $carte_produit);

        if ($carte->statut === CarteStatut::Active) {
            throw CarteProduitException::suppressionSurCarteActive($carte->id);
        }

        $carte_produit->delete();

        return ApiResponse::deleted('Produit retiré de la carte.');
    }
}
