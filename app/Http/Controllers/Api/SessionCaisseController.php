<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SessionCaisse\FermerSessionCaisseRequest;
use App\Http\Requests\SessionCaisse\OuvrirSessionCaisseRequest;
use App\Http\Resources\SessionCaisseResource;
use App\Models\SessionCaisse;
use App\Services\SessionCaisseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionCaisseController extends Controller
{
    /** Relations chargées pour une session complète (caisse → PDV → site). */
    /**
     * La fiche d'établissement remonte jusqu'ici pour la tolérance d'écart :
     * {@see SessionCaisseResource} la lit sans déclencher de requête.
     */
    private const RELATIONS = [
        'caisse.pointDeVente.site.parametreEtablissement',
        'user',
        'rapprochements.modePaiement',
    ];

    public function __construct(private readonly SessionCaisseService $service) {}

    /**
     * Liste les sessions (filtre optionnel ?caisse_id).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SessionCaisse::class);

        $sessions = SessionCaisse::query()
            ->with(self::RELATIONS)
            ->when(
                $request->filled('caisse_id'),
                fn (Builder $query) => $query->where('caisse_id', $request->integer('caisse_id'))
            )
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success(
            ['sessions' => SessionCaisseResource::collection($sessions)],
            'Sessions de caisse récupérées.'
        );
    }

    /**
     * Ouvre une session sur une caisse (l'utilisateur connecté est l'ouvreur).
     */
    public function store(OuvrirSessionCaisseRequest $request): JsonResponse
    {
        $session = $this->service->ouvrir($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new SessionCaisseResource($session),
            'Session de caisse ouverte.'
        );
    }

    public function show(SessionCaisse $session): JsonResponse
    {
        $this->authorize('view', $session);

        return ApiResponse::success(
            new SessionCaisseResource($session->load(self::RELATIONS)),
            'Session de caisse récupérée.'
        );
    }

    /**
     * Ferme une session ouverte : calcule montant_theorique, ecart, ferme_le.
     */
    public function fermer(FermerSessionCaisseRequest $request, SessionCaisse $session): JsonResponse
    {
        $session = $this->service->fermer($session, $request->validated(), $request->user()->id);

        return ApiResponse::updated(
            new SessionCaisseResource($session),
            'Session de caisse fermée.'
        );
    }
}
