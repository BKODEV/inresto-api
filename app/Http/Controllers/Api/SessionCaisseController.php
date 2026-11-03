<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\SessionCaisseException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\SessionCaisse\FermerSessionCaisseRequest;
use App\Http\Requests\SessionCaisse\OuvrirSessionCaisseRequest;
use App\Http\Resources\SessionCaisseResource;
use App\Models\SessionCaisse;
use App\Services\SessionCaisseService;
use App\Support\SitePerimetre;
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

        // Une session est une opération : elle appartient au site de son tiroir.
        // Sans ce bornage, la liste rendait TOUTES les sessions de la base —
        // montant théorique, écart et motif de forçage compris — à quiconque
        // portait `session_caisse.view`.
        $sites = SitePerimetre::resoudre(
            $request->user(),
            $request->filled('site_id') ? $request->integer('site_id') : null,
        );

        $sessions = SessionCaisse::query()
            ->with(self::RELATIONS)
            ->when(
                $sites !== null,
                fn (Builder $query) => $query->whereHas(
                    'caisse.pointDeVente',
                    fn (Builder $pdv) => $pdv->whereIn('site_id', $sites),
                )
            )
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
     * Ce que ce tiroir devrait contenir, MAINTENANT, contenant par contenant.
     *
     * Le calcul existait déjà — {@see SessionCaisse::theoriqueParMode()} — mais
     * n'était appelé qu'à la fermeture. On ne pouvait donc pas répondre, en
     * plein service, à « combien avons-nous en caisse ? ».
     *
     * Deux garde-fous, et le second est le vrai :
     *  - la permission `session_caisse.consulter_theorique`, absente du rôle
     *    caissier (portée par la policy, avec le périmètre de site) ;
     *  - le refus au PORTEUR d'une session encore ouverte, vérifié ici et non
     *    dans la policy : `before()` y laisse passer le super-admin, alors que
     *    cette règle-ci ne parle pas de droits mais d'intégrité du comptage.
     *    Celui qui comptera ce soir compte à l'aveugle, quel que soit son rôle.
     */
    public function position(Request $request, SessionCaisse $session): JsonResponse
    {
        $this->authorize('consulterTheorique', $session);

        if ($session->estOuverte() && $session->user_id === $request->user()->id) {
            throw SessionCaisseException::theoriqueRefuseAuPorteur($session->id);
        }

        return ApiResponse::success(
            $this->service->position($session->load(self::RELATIONS)),
            'Position de la caisse récupérée.'
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
