<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\RoleException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount('users')
            ->orderByDesc('niveau')
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            ['roles' => RoleResource::collection($roles)],
            'Rôles récupérés.'
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'api',
            'description' => $data['description'] ?? null,
            'niveau' => $data['niveau'],
            'is_system' => false,
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return ApiResponse::created(
            new RoleResource($role->load('permissions')->loadCount('users')),
            'Rôle créé avec succès.'
        );
    }

    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return ApiResponse::success(
            new RoleResource($role->load('permissions')->loadCount('users')),
            'Rôle récupéré.'
        );
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        $role->update([
            'name' => $data['name'] ?? $role->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $role->description,
            'niveau' => $data['niveau'] ?? $role->niveau,
        ]);

        if (array_key_exists('permissions', $data)) {
            $role->syncPermissions($data['permissions']);
        }

        return ApiResponse::updated(
            new RoleResource($role->load('permissions')->loadCount('users')),
            'Rôle mis à jour avec succès.'
        );
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        if ($role->is_system) {
            throw RoleException::systemeNonSupprimable();
        }

        $nbUsers = $role->users()->count();
        if ($nbUsers > 0) {
            throw RoleException::roleUtilise($nbUsers);
        }

        $role->delete();

        return ApiResponse::deleted('Rôle supprimé avec succès.');
    }
}
