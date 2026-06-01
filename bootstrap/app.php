<?php

use App\Exceptions\CommandeFournisseurException;
use App\Helpers\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->render(function (CommandeFournisseurException $e) {
            return ApiResponse::custom($e->status, 'error', $e->getMessage());
        });

        $exceptions->render(function (ValidationException $e) {
            return ApiResponse::unprocessableEntity(
                $e->errors(),
                'Les données fournies sont invalides.'
            );
        });

        $exceptions->render(function (AuthorizationException $e) {
            return ApiResponse::forbidden('Action non autorisée.');
        });

        $exceptions->render(function (AuthenticationException $e) {
            return ApiResponse::unauthorized('Non authentifié. Veuillez vous connecter.');
        });

        $exceptions->render(function (ModelNotFoundException $e) {
            $model = class_basename($e->getModel());

            return ApiResponse::notFound("Ressource {$model} introuvable.");
        });

    })->create();
