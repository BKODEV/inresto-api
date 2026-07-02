<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ResetPasswordRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    /**
     * Liste les utilisateurs. Un non-super-admin ne voit que ceux de ses sites.
     * Filtres : ?site_id, ?role, ?statut, recherche ?q (nom / prénom / identifiant).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $acteur = $request->user();

        $query = User::query()->with(['roles', 'sites']);

        // Périmètre : restreint aux utilisateurs partageant un site avec l'acteur.
        if (! $acteur->isSuperAdmin()) {
            $siteIds = $acteur->sites->pluck('id');
            $query->whereHas('sites', fn ($q) => $q->whereIn('sites.id', $siteIds));
        }

        if ($request->filled('site_id')) {
            $query->whereHas('sites', fn ($q) => $q->where('sites.id', $request->integer('site_id')));
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }

        if ($request->filled('q')) {
            $terme = '%'.$request->string('q').'%';
            $query->where(function ($q) use ($terme) {
                $q->where('nom', 'like', $terme)
                    ->orWhere('prenom', 'like', $terme)
                    ->orWhere('identifiant', 'like', $terme);
            });
        }

        $users = $query->orderBy('nom')->orderBy('prenom')->get();

        return ApiResponse::success(
            ['users' => UserResource::collection($users)],
            'Utilisateurs récupérés.'
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->service->creer($request->user(), $request->validated());

        return ApiResponse::created(new UserResource($user), 'Utilisateur créé avec succès.');
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::success(new UserResource($user->load(['roles', 'sites'])), 'Utilisateur récupéré.');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->service->mettreAJour($request->user(), $user, $request->validated());

        return ApiResponse::updated(new UserResource($updated), 'Utilisateur mis à jour avec succès.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $this->service->supprimer($request->user(), $user);

        return ApiResponse::deleted('Utilisateur supprimé avec succès.');
    }

    public function activer(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $this->service->activer($request->user(), $user);

        return ApiResponse::updated(new UserResource($updated), 'Utilisateur activé.');
    }

    public function desactiver(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $this->service->desactiver($request->user(), $user);

        return ApiResponse::updated(new UserResource($updated), 'Utilisateur désactivé.');
    }

    /**
     * Réinitialisation mode a : l'admin définit directement un nouveau mot de passe.
     */
    public function resetPassword(ResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->service->reinitialiserMotDePasse($request->user(), $user, $request->validated()['new_password']);

        return ApiResponse::success(message: 'Mot de passe réinitialisé.');
    }

    /**
     * Réinitialisation mode b : l'admin déclenche un lien envoyé par email.
     */
    public function sendResetLink(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $this->service->envoyerLienReset($request->user(), $user);

        return ApiResponse::success(message: 'Lien de réinitialisation envoyé par email.');
    }
}
