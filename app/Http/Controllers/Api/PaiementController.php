<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Paiement\StorePaiementRequest;
use App\Http\Resources\PaiementResource;
use App\Models\CommandeClient;
use App\Services\PaiementService;
use Illuminate\Http\JsonResponse;

class PaiementController extends Controller
{
    public function __construct(private readonly PaiementService $service) {}

    /**
     * Enregistre un paiement sur une commande servie.
     */
    public function store(StorePaiementRequest $request, CommandeClient $commande): JsonResponse
    {
        $paiement = $this->service->ajouter($commande, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new PaiementResource($paiement),
            'Paiement enregistré.'
        );
    }
}
