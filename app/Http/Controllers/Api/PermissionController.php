<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Catalogue des permissions en LECTURE SEULE, groupé par module avec libellés
     * humains. Alimente l'écran de rôles (cases à cocher). L'admin assigne des
     * permissions existantes, il n'en crée jamais.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return ApiResponse::success(
            ['modules' => PermissionCatalog::grouped()],
            'Catalogue des permissions récupéré.'
        );
    }
}
