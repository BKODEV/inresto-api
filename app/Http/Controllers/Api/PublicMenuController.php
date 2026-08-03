<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicMenuResource;
use App\Services\PublicMenuService;
use Illuminate\Http\JsonResponse;

/**
 * Consultation du menu par un client depuis le QR de sa table.
 *
 * SEUL controller de l'application accessible sans authentification : tout ce
 * qu'il renvoie est public. Lecture seule, aucun utilisateur, aucune trace.
 * En V1 le client consulte uniquement ; la commande à table viendra en V2 sur
 * ce même jeton (d'où un jeton par table, et non par point de vente).
 */
class PublicMenuController extends Controller
{
    public function __construct(private readonly PublicMenuService $service) {}

    public function show(string $public_token): JsonResponse
    {
        $table = $this->service->tableParToken($public_token);

        // Jeton inconnu (QR erroné, table supprimée, jeton régénéré) : message
        // neutre, sans indiquer au visiteur ce qui a échoué.
        if ($table === null) {
            return ApiResponse::notFound('Menu indisponible.');
        }

        return ApiResponse::success(
            new PublicMenuResource($this->service->menuPourTable($table)),
            'Menu récupéré.'
        );
    }
}
