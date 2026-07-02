<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property string $name
 * @property string|null $description
 * @property int $niveau
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    public const SUPER_ADMIN = 'super-admin';

    /**
     * Niveaux de sécurité des rôles système (hiérarchie anti-escalade).
     * super-admin court-circuite toute la hiérarchie.
     *
     * @var array<string, int>
     */
    public const NIVEAUX = [
        'super-admin' => 100,
        'gerant' => 50,
        'chef-cuisine' => 30,
        'econome' => 30,
        'caissier' => 20,
        'serveur' => 10,
    ];

    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'niveau',
        'is_system',
    ];

    protected $casts = [
        'niveau' => 'integer',
        'is_system' => 'boolean',
    ];
}
