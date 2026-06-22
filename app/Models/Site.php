<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site d'exploitation. Chaque site a SA cuisine, donc SON stock et SON budget,
 * et regroupe plusieurs points de vente (Lounge, Restaurant) où se fait le CA.
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $table = 'sites';

    protected $fillable = [
        'nom',
        'code',
        'adresse',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'statut' => Statut::class,
    ];

    public function pointsDeVente(): HasMany
    {
        return $this->hasMany(PointDeVente::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_site');
    }

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
