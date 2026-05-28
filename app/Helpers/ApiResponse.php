<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(mixed $data = [], string $message = 'Opération réussie.'): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    public static function created(mixed $data = [], string $message = 'Ressource créée avec succès.'): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], 201);
    }

    public static function updated(mixed $data = [], string $message = 'Ressource mise à jour avec succès.'): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    public static function deleted(string $message = 'Ressource supprimée avec succès.'): JsonResponse
    {
        return response()->json([
            'status'  => 'success',
            'message' => $message,
        ], 200);
    }

    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    public static function badRequest(string $message = 'Données de requête non valides.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], 400);
    }

    public static function unauthorized(string $message = 'Non autorisé.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], 401);
    }

    public static function forbidden(string $message = 'Accès interdit.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], 403);
    }

    public static function notFound(string $message = 'Ressource non trouvée.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], 404);
    }

    public static function unprocessableEntity(array $errors = [], string $message = 'Les données fournies sont invalides.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    public static function serverError(string $message = 'Erreur interne du serveur.'): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
        ], 500);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function custom(int $statusCode, string $status, string $message = '', array $data = []): JsonResponse
    {
        $payload = [
            'status'  => $status,
            'message' => $message,
        ];

        if ($data !== []) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $statusCode);
    }
}
