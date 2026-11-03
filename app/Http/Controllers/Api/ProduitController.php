<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StatutFicheTechnique;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Produit\StoreProduitRequest;
use App\Http\Requests\Produit\UpdateProduitRequest;
use App\Http\Requests\Produit\UploadPhotoProduitRequest;
use App\Http\Resources\ProduitResource;
use App\Models\Produit;
use App\Services\ProduitService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduitController extends Controller
{
    public function __construct(private readonly ProduitService $service) {}

    /**
     * Liste les produits (filtres optionnels ?categorie_id et ?statut).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Produit::class);

        $produits = Produit::query()
            ->with('categorie')
            ->when(
                $request->filled('categorie_id'),
                fn (Builder $query) => $query->where('categorie_id', $request->integer('categorie_id'))
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'produits' => ProduitResource::collection($produits->items()),
            'pagination' => [
                'total' => $produits->total(),
                'par_page' => $produits->perPage(),
                'page_courante' => $produits->currentPage(),
                'derniere_page' => $produits->lastPage(),
            ],
        ], 'Produits récupérés.');
    }

    public function store(StoreProduitRequest $request): JsonResponse
    {
        $produit = $this->service->creer($request->validated());

        return ApiResponse::created(
            new ProduitResource($produit),
            'Produit enregistré.'
        );
    }

    /**
     * Affiche un produit avec sa catégorie ET sa fiche technique active (lignes
     * incluses) : le détail produit est le point d'entrée unique de la recette.
     */
    public function show(Produit $produit): JsonResponse
    {
        $this->authorize('view', $produit);

        $produit->load([
            'categorie',
            'fichesTechniques' => fn ($query) => $query
                ->where('statut', StatutFicheTechnique::Active->value)
                ->with([
                    'lignesMatiere.matierePremiere',
                    'lignesMatiere.unite',
                    'lignesSousRecette.sousRecette',
                    // La méthodologie fait partie de la fiche : c'est d'ici que
                    // le drawer d'édition repart. Sans elle, l'écran affiche une
                    // recette vide après enregistrement et la version suivante
                    // effacerait les étapes.
                    'etapes',
                    'materiels',
                    'pointsCritiques',
                ]),
        ]);

        return ApiResponse::success(
            new ProduitResource($produit),
            'Produit récupéré.'
        );
    }

    public function update(UpdateProduitRequest $request, Produit $produit): JsonResponse
    {
        $produit = $this->service->modifier($produit, $request->validated());

        return ApiResponse::updated(
            new ProduitResource($produit),
            'Produit mis à jour.'
        );
    }

    /**
     * Soft delete, refusé (409) si le produit est en fiche technique active.
     */
    public function destroy(Produit $produit): JsonResponse
    {
        $this->authorize('delete', $produit);

        $this->service->supprimer($produit);

        return ApiResponse::deleted('Produit supprimé.');
    }

    /**
     * Envoie/écrase la photo du produit — celle affichée au client sur le menu
     * public. Seule voie d'écriture du champ `photo`.
     */
    public function uploadPhoto(UploadPhotoProduitRequest $request, Produit $produit): JsonResponse
    {
        $produit = $this->service->enregistrerPhoto($produit, $request->file('photo'));

        return ApiResponse::updated(new ProduitResource($produit), 'Photo mise à jour.');
    }

    /**
     * Retire la photo du produit (fichier effacé, `photo` remis à null).
     */
    public function deletePhoto(Produit $produit): JsonResponse
    {
        $this->authorize('update', $produit);

        $produit = $this->service->supprimerPhoto($produit);

        return ApiResponse::updated(new ProduitResource($produit), 'Photo supprimée.');
    }
}
