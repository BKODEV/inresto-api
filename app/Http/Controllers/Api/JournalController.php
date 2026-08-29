<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\IndexJournalRequest;
use App\Services\JournalService;
use App\Support\SitePerimetre;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Journal d'activité : frise chronologique des gestes sensibles du service —
 * annulations, remises, paiements, clôtures de note, versements, impressions.
 *
 * Strictement en lecture. Rien n'est écrit ici : le journal relit les traces
 * que chaque module produit déjà avec son contexte métier propre.
 */
class JournalController extends Controller
{
    public function __construct(private readonly JournalService $service) {}

    public function index(IndexJournalRequest $request): JsonResponse
    {
        $filtres = $request->validated();

        $sites = SitePerimetre::resoudre(
            $request->user(),
            isset($filtres['site_id']) ? (int) $filtres['site_id'] : null,
        );

        // Fenêtre par défaut : la journée courante. Le journal s'assemble en
        // PHP, il ne doit jamais être invité à lire toute l'histoire.
        $debut = isset($filtres['date_debut'])
            ? Carbon::parse($filtres['date_debut'])->startOfDay()
            : now()->startOfDay();
        $fin = isset($filtres['date_fin'])
            ? Carbon::parse($filtres['date_fin'])->endOfDay()
            : now()->endOfDay();

        $evenements = $this->service->evenements(
            $debut,
            $fin,
            $sites,
            $filtres['type'] ?? [],
            isset($filtres['point_de_vente_id']) ? (int) $filtres['point_de_vente_id'] : null,
        );

        return ApiResponse::success([
            'evenements' => $evenements->map(fn (array $e): array => [
                ...$e,
                'date_heure' => $e['date_heure']?->toIso8601String(),
            ])->all(),
            'periode' => [
                'debut' => $debut->toIso8601String(),
                'fin' => $fin->toIso8601String(),
            ],
            'total' => $evenements->count(),
        ], "Journal d'activité récupéré.");
    }
}
