<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CarteStatut;
use App\Exceptions\CarteProduitException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarteProduit\StoreCarteProduitLotRequest;
use App\Http\Requests\CarteProduit\StoreCarteProduitRequest;
use App\Http\Requests\CarteProduit\UpdateCarteProduitRequest;
use App\Http\Resources\CarteProduitResource;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\Produit;
use App\Services\OrdreService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CarteProduitController extends Controller
{
    /**
     * Liste les produits d'une carte (lignes carte_produit + produit + prix).
     */
    public function index(Carte $carte): JsonResponse
    {
        $this->authorize('viewAny', CarteProduit::class);

        // Catégorie du produit chargée pour permettre le regroupement par section
        // (Entrée / Plat / Dessert…) ; tri par `ordre` au sein de chaque section.
        $lignes = CarteProduit::query()
            ->where('carte_id', $carte->id)
            ->with('produit.categorie')
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            ['produits' => CarteProduitResource::collection($lignes)],
            'Produits de la carte récupérés.'
        );
    }

    /**
     * Ajoute un produit (actif) à la carte. La carte n'a pas besoin d'être active.
     * L'ordre est posé dans la section du produit (sa catégorie) : ordre fourni
     * => insertion avec décalage des voisins ; absent => fin de section.
     */
    public function store(StoreCarteProduitRequest $request, Carte $carte, OrdreService $ordreService): JsonResponse
    {
        $data = $request->validated();
        $produit = Produit::findOrFail($data['produit_id']);

        $ligne = DB::transaction(function () use ($carte, $produit, $data, $ordreService) {
            $data['ordre'] = $ordreService->placer(
                fn (): Builder => $this->sectionDeProduit($carte->id, $produit->categorie_id),
                $data['ordre'] ?? null,
            );

            return CarteProduit::create([
                'carte_id' => $carte->id,
                ...$data,
            ]);
        });

        return ApiResponse::created(
            new CarteProduitResource($ligne->load('produit.categorie')),
            'Produit ajouté à la carte.'
        );
    }

    /**
     * Ajoute plusieurs produits d'un coup — composer une carte de trente plats
     * un par un est impraticable.
     *
     * Tout ou rien : un seul produit en défaut (inactif, déjà sur la carte,
     * doublon dans la requête) fait échouer le lot entier en 422. Un ajout
     * partiel laisserait l'utilisateur deviner ce qui est passé.
     *
     * Chaque ligne se pose en fin de la section de sa catégorie, dans l'ordre
     * reçu : le lot respecte donc l'ordre d'affichage choisi à l'écran.
     */
    public function storeLot(StoreCarteProduitLotRequest $request, Carte $carte, OrdreService $ordreService): JsonResponse
    {
        $lignesDemandees = $request->validated()['lignes'];

        $ids = DB::transaction(function () use ($carte, $lignesDemandees, $ordreService): array {
            $produits = Produit::query()
                ->whereIn('id', array_column($lignesDemandees, 'produit_id'))
                ->get()
                ->keyBy('id');

            $ids = [];
            foreach ($lignesDemandees as $ligne) {
                $produit = $produits[$ligne['produit_id']];

                $ids[] = CarteProduit::create([
                    'carte_id' => $carte->id,
                    ...$ligne,
                    // Recalculé à chaque itération : les lignes précédentes du lot
                    // ont déjà décalé le maximum de leur section.
                    'ordre' => $ordreService->placer(
                        fn (): Builder => $this->sectionDeProduit($carte->id, $produit->categorie_id),
                        null,
                    ),
                ])->id;
            }

            return $ids;
        });

        $creees = CarteProduit::query()
            ->whereIn('id', $ids)
            ->with('produit.categorie')
            ->orderBy('ordre')
            ->get();

        return ApiResponse::created(
            CarteProduitResource::collection($creees),
            $creees->count().' produit(s) ajouté(s) à la carte.'
        );
    }

    /**
     * Modifie le prix de vente, le statut ou l'ordre d'une ligne. Changer
     * l'ordre réinsère la ligne dans sa section en décalant les voisins.
     */
    public function update(UpdateCarteProduitRequest $request, Carte $carte, CarteProduit $carte_produit, OrdreService $ordreService): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($carte, $carte_produit, $data, $ordreService) {
            if (array_key_exists('ordre', $data) && (int) $data['ordre'] !== $carte_produit->ordre) {
                $carte_produit->loadMissing('produit');
                $data['ordre'] = $ordreService->placer(
                    fn (): Builder => $this->sectionDeProduit($carte->id, $carte_produit->produit->categorie_id)
                        ->whereKeyNot($carte_produit->id),
                    $data['ordre'],
                );
            }

            $carte_produit->update($data);
        });

        return ApiResponse::updated(
            new CarteProduitResource($carte_produit->load('produit.categorie')),
            'Ligne de carte mise à jour.'
        );
    }

    /**
     * Périmètre de tri d'une ligne : les produits de la MÊME carte appartenant
     * à la MÊME section (catégorie du produit). L'ordre est local à la section.
     */
    private function sectionDeProduit(int $carteId, int $categorieId): Builder
    {
        return CarteProduit::query()
            ->where('carte_id', $carteId)
            ->whereHas('produit', fn (Builder $q) => $q->where('categorie_id', $categorieId));
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
