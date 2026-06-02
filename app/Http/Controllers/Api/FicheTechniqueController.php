<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\StatutFicheTechnique;
use App\Exceptions\FicheTechniqueException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\FicheTechnique\StoreFicheTechniqueRequest;
use App\Http\Requests\FicheTechnique\UpdateFicheTechniqueRequest;
use App\Http\Resources\FicheTechniqueResource;
use App\Models\FicheTechnique;
use App\Services\FicheTechniqueService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FicheTechniqueController extends Controller
{
    /** Relations chargées pour une fiche complète. */
    private const RELATIONS = [
        'produit',
        'lignesMatiere.matierePremiere',
        'lignesMatiere.unite',
        'lignesSousRecette.sousRecette',
    ];

    public function __construct(private readonly FicheTechniqueService $service) {}

    /**
     * Liste les fiches ACTIVES (filtre optionnel ?produit_id, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FicheTechnique::class);

        $fiches = FicheTechnique::query()
            ->where('statut', StatutFicheTechnique::Active->value)
            ->with(self::RELATIONS)
            ->when(
                $request->filled('produit_id'),
                fn (Builder $query) => $query->where('produit_id', $request->integer('produit_id'))
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'fiches_techniques' => FicheTechniqueResource::collection($fiches->items()),
            'pagination' => [
                'total' => $fiches->total(),
                'par_page' => $fiches->perPage(),
                'page_courante' => $fiches->currentPage(),
                'derniere_page' => $fiches->lastPage(),
            ],
        ], 'Fiches techniques récupérées.');
    }

    /**
     * Crée une fiche, ou une nouvelle version si le produit en a déjà une active.
     */
    public function store(StoreFicheTechniqueRequest $request): JsonResponse
    {
        $fiche = $this->enregistrer($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new FicheTechniqueResource($fiche),
            'Fiche technique enregistrée.'
        );
    }

    /**
     * Affiche une fiche (active ou archivée) avec ses lignes.
     */
    public function show(FicheTechnique $fiche_technique): JsonResponse
    {
        $this->authorize('view', $fiche_technique);

        $fiche_technique->load(self::RELATIONS);

        return ApiResponse::success(
            new FicheTechniqueResource($fiche_technique),
            'Fiche technique récupérée.'
        );
    }

    /**
     * Alias de store : même logique de versioning, basée sur le produit.
     */
    public function update(UpdateFicheTechniqueRequest $request, FicheTechnique $fiche_technique): JsonResponse
    {
        $fiche = $this->enregistrer($request->validated(), $request->user()->id);

        return ApiResponse::updated(
            new FicheTechniqueResource($fiche),
            'Fiche technique mise à jour (nouvelle version).'
        );
    }

    /**
     * Supprime la fiche active si aucune carte active ne la référence. Une fiche
     * archivée n'est jamais supprimable (historique).
     */
    public function destroy(FicheTechnique $fiche_technique): JsonResponse
    {
        $this->authorize('delete', $fiche_technique);

        if (! $fiche_technique->estActive()) {
            throw FicheTechniqueException::suppressionFicheArchivee($fiche_technique->id);
        }

        if ($fiche_technique->estReferenceeDansCarteActive()) {
            throw FicheTechniqueException::refereceeDansCarteActive($fiche_technique->id);
        }

        $fiche_technique->delete();

        return ApiResponse::deleted('Fiche technique supprimée.');
    }

    /**
     * Historique : toutes les versions d'un produit (actives + archivées).
     */
    public function historique(Request $request, int $produit_id): JsonResponse
    {
        $this->authorize('viewAny', FicheTechnique::class);

        $fiches = FicheTechnique::query()
            ->where('produit_id', $produit_id)
            ->with(self::RELATIONS)
            ->orderByDesc('version')
            ->get();

        return ApiResponse::success(
            ['fiches_techniques' => FicheTechniqueResource::collection($fiches)],
            'Historique des fiches techniques récupéré.'
        );
    }

    /**
     * Aiguille vers création (pas de fiche active) ou nouvelle version.
     *
     * @param  array{produit_id: int, lignes_matieres?: array<int, array<string, mixed>>, lignes_sous_recettes?: array<int, array<string, mixed>>}  $data
     */
    private function enregistrer(array $data, string $userId): FicheTechnique
    {
        $ficheActive = FicheTechnique::query()
            ->where('produit_id', $data['produit_id'])
            ->where('statut', StatutFicheTechnique::Active->value)
            ->first();

        return $ficheActive !== null
            ? $this->service->modifier($ficheActive, $data, $userId)
            : $this->service->creer($data, $userId);
    }
}
