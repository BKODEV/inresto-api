<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\ConsumeResetTokenRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Route PUBLIQUE (hors auth) : consomme le token reçu par email (lien envoyé par
 * un administrateur) et applique le nouveau mot de passe choisi par l'utilisateur.
 * Réutilise le Password broker de Laravel (vérifie le token signé/expirable).
 */
class PasswordResetController extends Controller
{
    public function reset(ConsumeResetTokenRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status === Password::PasswordReset) {
            return ApiResponse::success(message: 'Mot de passe réinitialisé. Vous pouvez vous connecter.');
        }

        return ApiResponse::custom(422, 'error', match ($status) {
            Password::InvalidToken => 'Lien invalide ou expiré.',
            Password::InvalidUser => 'Aucun compte ne correspond à cet email.',
            default => 'Réinitialisation impossible.',
        });
    }
}
