<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Authentifie l'utilisateur et retourne un token Sanctum.
     *
     * On vérifie manuellement par `identifiant` (champ custom) plutôt qu'avec
     * Auth::attempt(), qui s'appuie sur `email` et ouvre une session — inadapté pour une API stateless.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        /** @var User|null $user */
        $user = User::where('identifiant', $data['identifiant'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return ApiResponse::unauthorized('Identifiant ou mot de passe incorrect.');
        }

        // Compte désactivé : on rejette avant d'émettre le token
        if (! $user->isActif()) {
            return ApiResponse::forbidden('Votre compte est désactivé. Veuillez contacter l\'administrateur.');
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $deviceName = $data['device_name'] ?? ($request->userAgent() ?? 'api');
        $token = $user->createToken($deviceName)->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->transformUser($user),
        ], 'Connexion réussie.');
    }

    /**
     * Retourne le profil de l'utilisateur authentifié.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->transformUser($user), 'Profil utilisateur récupéré.');
    }

    /**
     * Révoque uniquement le token courant (déconnexion du device actif).
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        $token?->delete();

        return ApiResponse::success(message: 'Déconnexion réussie.');
    }

    /**
     * @return array<string, mixed>
     */
    private function transformUser(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'nom' => $user->nom,
            'prenom' => $user->prenom,
            'identifiant' => $user->identifiant,
            'email' => $user->email,
            'telephone' => $user->telephone,
            'photo' => $user->photo,
            'statut' => $user->statut,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            // Périmètre métier : sites de rattachement de l'utilisateur (cuisine/stock/budget).
            // Le super-admin n'est borné par aucun périmètre : il obtient tous les sites actifs.
            'sites' => $user->sitesPerimetre()->map(fn (Site $site) => [
                'id' => $site->id,
                'nom' => $site->nom,
                'code' => $site->code,
                'statut' => $site->statut->value,
            ])->values(),
        ];
    }
}
