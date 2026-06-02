<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\InventaireException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventaire\StoreInventaireRequest;
use App\Http\Requests\Inventaire\StoreLigneInventaireRequest;
use App\Http\Resources\InventaireResource;
use App\Http\Resources\LigneInventaireResource;
use App\Models\Inventaire;
use App\Models\LigneInventaire;
use App\Models\MouvementStock;
use App\Models\Stock;
use App\Services\InventaireService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InventaireController extends Controller
{
    public function __construct(private readonly InventaireService $service) {}

    /**
     * Liste les inventaires (filtre optionnel ?site_id, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Inventaire::class);

        $inventaires = Inventaire::query()
            ->with('site')
            ->when(
                $request->filled('site_id'),
                fn (Builder $query) => $query->where('site_id', $request->integer('site_id'))
            )
            ->orderByDesc('date_inventaire')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'inventaires' => InventaireResource::collection($inventaires->items()),
            'pagination' => [
                'total' => $inventaires->total(),
                'par_page' => $inventaires->perPage(),
                'page_courante' => $inventaires->currentPage(),
                'derniere_page' => $inventaires->lastPage(),
            ],
        ], 'Inventaires récupérés.');
    }

    /**
     * Crée un inventaire en cours (refus si un autre est déjà en cours sur le site).
     */
    public function store(StoreInventaireRequest $request): JsonResponse
    {
        $inventaire = $this->service->creer($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new InventaireResource($inventaire->load('site')),
            'Inventaire créé.'
        );
    }

    /**
     * Affiche un inventaire avec ses lignes.
     */
    public function show(Inventaire $inventaire): JsonResponse
    {
        $this->authorize('view', $inventaire);

        $inventaire->load(['site', 'lignes.matierePremiere']);

        return ApiResponse::success(
            new InventaireResource($inventaire),
            'Inventaire récupéré.'
        );
    }

    /**
     * Supprime un inventaire en cours sans lignes. 409 sinon (clôturé ou avec lignes).
     */
    public function destroy(Inventaire $inventaire): JsonResponse
    {
        $this->authorize('delete', $inventaire);

        if (! $inventaire->estEnCours() || $inventaire->lignes()->exists()) {
            throw InventaireException::suppressionAvecLignes($inventaire->id);
        }

        $inventaire->delete();

        return ApiResponse::deleted('Inventaire supprimé.');
    }

    /**
     * Ajoute ou met à jour une ligne (une matière par inventaire).
     */
    public function lignes(StoreLigneInventaireRequest $request, Inventaire $inventaire): JsonResponse
    {
        $ligne = $this->service->ajouterOuModifierLigne($inventaire, $request->validated(), $request->user()->id);

        return ApiResponse::success(
            new LigneInventaireResource($ligne->load('matierePremiere')),
            'Ligne d\'inventaire enregistrée.'
        );
    }

    /**
     * Supprime une ligne — inventaire en cours uniquement.
     */
    public function supprimerLigne(Inventaire $inventaire, LigneInventaire $ligne): JsonResponse
    {
        $this->authorize('update', $inventaire);
        $this->assertLigneAppartient($inventaire, $ligne);

        $this->service->supprimerLigne($inventaire, $ligne, request()->user()->id);

        return ApiResponse::deleted('Ligne d\'inventaire supprimée.');
    }

    /**
     * Clôture l'inventaire : génère les corrections de stock (irréversible).
     */
    public function cloturer(Inventaire $inventaire): JsonResponse
    {
        $this->authorize('update', $inventaire);

        $inventaire = $this->service->cloturer($inventaire, request()->user()->id);

        return ApiResponse::updated(
            new InventaireResource($inventaire),
            'Inventaire clôturé : corrections de stock générées.'
        );
    }

    /**
     * Rapport de couverture (calculé à la volée).
     */
    public function rapport(Inventaire $inventaire): JsonResponse
    {
        $this->authorize('view', $inventaire);

        $inventaire->load('lignes.matierePremiere');

        $nbInventoriees = $inventaire->lignes->count();
        $nbSite = $this->nbMatieresDuSite($inventaire->site_id);
        // Chaîne à 1 décimale (toujours), pour un rendu stable côté API.
        $tauxCouverture = number_format($nbSite > 0 ? $nbInventoriees / $nbSite * 100 : 0, 1, '.', '');

        return ApiResponse::success([
            'inventaire_id' => $inventaire->id,
            'statut' => $inventaire->statut->value,
            'nb_matieres_inventoriees' => $nbInventoriees,
            'nb_matieres_site' => $nbSite,
            'taux_couverture' => $tauxCouverture,
            'valeur_totale_stock' => $inventaire->estEnCours() ? null : $inventaire->valeur_totale_stock,
            'lignes' => LigneInventaireResource::collection($inventaire->lignes),
        ], 'Rapport d\'inventaire récupéré.');
    }

    /**
     * Nombre de matières « présentes » sur le site : ayant un stock non nul OU
     * ayant déjà eu des mouvements sur ce site.
     */
    private function nbMatieresDuSite(int $siteId): int
    {
        $avecStock = Stock::query()
            ->where('site_id', $siteId)
            ->where('stock_courant', '!=', 0)
            ->pluck('matiere_premiere_id');

        $avecMouvement = MouvementStock::query()
            ->where('site_id', $siteId)
            ->pluck('matiere_premiere_id');

        return $avecStock->merge($avecMouvement)->unique()->count();
    }

    /**
     * Garantit que la ligne appartient bien à l'inventaire de l'URL.
     */
    private function assertLigneAppartient(Inventaire $inventaire, LigneInventaire $ligne): void
    {
        if ($ligne->inventaire_id !== $inventaire->id) {
            throw new NotFoundHttpException('Ligne introuvable pour cet inventaire.');
        }
    }
}
