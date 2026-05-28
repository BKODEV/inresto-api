<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['nom', 'prenom', 'identifiant', 'email', 'password', 'telephone', 'photo', 'pin_code', 'statut', 'last_login_at'])]
#[Hidden(['password', 'remember_token', 'pin_code'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $guard_name = 'api';

    protected $fillable = [
        'nom',
        'prenom',
        'identifiant',
        'email',
        'password',
        'telephone',
        'photo',
        'pin_code',
        'statut',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at'     => 'datetime',
        'password'          => 'hashed',
    ];

    // Connexion par identifiant au lieu de email
    public function getAuthIdentifierName(): string
    {
        return 'identifiant';
    }

    public function isActif(): bool
    {
        return $this->statut === 'actif';
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }
}
