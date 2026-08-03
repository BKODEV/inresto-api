<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\CommandeFournisseurException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\CommandeFournisseur\StoreCommandeFournisseurRequest;
use App\Http\Requests\CommandeFournisseur\UpdateCommandeFournisseurRequest;
use App\Http\Resources\CommandeFournisseurResource;
use App\Models\CommandeFournisseur;
use App\Services\CommandeFournisseurService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandeFournisseurController extends Controller
{
    public function __construct(private readonly CommandeFournisseurService $service) {}

    /**
     * Liste les commandes fournisseur (filtres fournisseur/statut, pagination).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CommandeFournisseur::class);

        $commandes = CommandeFournisseur::query()
            ->with(['fournisseur'])
            ->when(
                $request->filled('fournisseur_id'),
                fn (Builder $query) => $query->where('fournisseur_id', $request->integer('fournisseur_id'))
            )
            ->when(
                $request->filled('statut'),
                fn (Builder $query) => $query->where('statut', $request->string('statut'))
            )
            ->orderByDesc('date_commande')
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success([
            'commandes' => CommandeFournisseurResource::collection($commandes->items()),
            'pagination' => [
                'total' => $commandes->total(),
                'par_page' => $commandes->perPage(),
                'page_courante' => $commandes->currentPage(),
                'derniere_page' => $commandes->lastPage(),
            ],
        ], 'Commandes fournisseur récupérées.');
    }

    /**
     * Crée une commande en brouillon (avec ses lignes éventuelles).
     */
    public function store(StoreCommandeFournisseurRequest $request): JsonResponse
    {
        $donnees = $request->validated();

        $commande = $this->service->creer(
            entete: [
                'fournisseur_id' => $donnees['fournisseur_id'],
                'date_commande' => $donnees['date_commande'] ?? null,
                'notes' => $donnees['notes'] ?? null,
            ],
            lignes: $donnees['lignes'] ?? [],
            userId: $request->user()->id,
        );

        return ApiResponse::created(
            new CommandeFournisseurResource($commande),
            'Commande fournisseur créée avec succès.'
        );
    }

    /**
     * Affiche une commande avec ses lignes.
     */
    public function show(CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $this->authorize('view', $commande_fournisseur);

        $this->service->chargerRelations($commande_fournisseur);

        return ApiResponse::success(
            new CommandeFournisseurResource($commande_fournisseur),
            'Commande fournisseur récupérée.'
        );
    }

    /**
     * Met à jour l'entête — uniquement en brouillon.
     */
    public function update(UpdateCommandeFournisseurRequest $request, CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $commande = $this->service->mettreAJourEntete($commande_fournisseur, $request->validated());

        return ApiResponse::updated(
            new CommandeFournisseurResource($commande),
            'Commande fournisseur mise à jour avec succès.'
        );
    }

    /**
     * Supprime une commande — uniquement en brouillon.
     */
    public function destroy(CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $this->authorize('delete', $commande_fournisseur);

        if (! $commande_fournisseur->estModifiable()) {
            throw CommandeFournisseurException::nonModifiable($commande_fournisseur->statut);
        }

        $commande_fournisseur->delete();

        return ApiResponse::deleted('Commande fournisseur supprimée avec succès.');
    }

    /**
     * Transition brouillon -> envoyee (verrouille la commande en écriture).
     */
    public function envoyer(CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $this->authorize('update', $commande_fournisseur);

        $commande = $this->service->marquerEnvoyee($commande_fournisseur);

        return ApiResponse::updated(
            new CommandeFournisseurResource($this->service->chargerRelations($commande)),
            'Commande fournisseur envoyée.'
        );
    }

    /**
     * Transition (sauf depuis cloturee) -> annulee.
     */
    public function annuler(CommandeFournisseur $commande_fournisseur): JsonResponse
    {
        $this->authorize('update', $commande_fournisseur);

        $commande = $this->service->annuler($commande_fournisseur);

        return ApiResponse::updated(
            new CommandeFournisseurResource($this->service->chargerRelations($commande)),
            'Commande fournisseur annulée.'
        );
    }
}
