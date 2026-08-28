<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Parametres;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ModeDePaiement\StoreModeDePaiementRequest;
use App\Http\Requests\ModeDePaiement\UpdateModeDePaiementRequest;
use App\Http\Resources\ModeDePaiementResource;
use App\Models\ModeDePaiement;
use App\Services\OrdreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ModeDePaiementController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', ModeDePaiement::class);

        return ApiResponse::success(
            // Ordre explicite et non l'ordre physique de la table : le premier
            // mode sert de repère à la caisse, et PostgreSQL ne garantit aucun
            // ordre naturel — une simple mise à jour pouvait le faire basculer.
            ModeDePaiementResource::collection(
                ModeDePaiement::orderBy('ordre')->orderBy('id')->get()
            ),
            'Modes de paiement récupérés.'
        );
    }

    public function store(StoreModeDePaiementRequest $request, OrdreService $ordreService): JsonResponse
    {
        $data = $request->validated();

        $mode = DB::transaction(function () use ($data, $ordreService) {
            $data['ordre'] = $ordreService->placer(
                fn () => ModeDePaiement::query(),
                $data['ordre'] ?? null,
            );

            return ModeDePaiement::create($data);
        });

        return ApiResponse::created(
            new ModeDePaiementResource($mode),
            'Mode de paiement créé avec succès.'
        );
    }

    public function show(ModeDePaiement $modeDePaiement): JsonResponse
    {
        $this->authorize('view', $modeDePaiement);

        return ApiResponse::success(
            new ModeDePaiementResource($modeDePaiement),
            'Mode de paiement récupéré.'
        );
    }

    public function update(
        UpdateModeDePaiementRequest $request,
        ModeDePaiement $modeDePaiement,
        OrdreService $ordreService,
    ): JsonResponse {
        $data = $request->validated();

        DB::transaction(function () use ($modeDePaiement, $data, $ordreService) {
            if (array_key_exists('ordre', $data) && (int) $data['ordre'] !== $modeDePaiement->ordre) {
                $data['ordre'] = $ordreService->placer(
                    fn () => ModeDePaiement::query()->whereKeyNot($modeDePaiement->id),
                    $data['ordre'],
                );
            }

            $modeDePaiement->update($data);
        });

        return ApiResponse::updated(
            new ModeDePaiementResource($modeDePaiement),
            'Mode de paiement mis à jour avec succès.'
        );
    }

    public function destroy(ModeDePaiement $modeDePaiement): JsonResponse
    {
        $this->authorize('delete', $modeDePaiement);

        $modeDePaiement->delete();

        return ApiResponse::deleted('Mode de paiement supprimé avec succès.');
    }
}
