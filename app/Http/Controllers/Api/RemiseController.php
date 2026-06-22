<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Remise\StoreRemiseRequest;
use App\Http\Resources\RemiseResource;
use App\Models\CommandeClient;
use App\Services\RemiseService;
use Illuminate\Http\JsonResponse;

class RemiseController extends Controller
{
    public function __construct(private readonly RemiseService $service) {}

    /**
     * Applique une remise à une commande (ouverte ou servie). Au-delà du seuil,
     * la permission remise.autoriser_au_dela_seuil est requise.
     */
    public function store(StoreRemiseRequest $request, CommandeClient $commande): JsonResponse
    {
        $remise = $this->service->appliquer($commande, $request->validated(), $request->user()->id);

        return ApiResponse::created(
            new RemiseResource($remise),
            'Remise appliquée.'
        );
    }
}
