<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\CaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Caisse extends Model
{
    /** @use HasFactory<CaisseFactory> */
    use HasFactory;

    protected $fillable = [
        'point_de_vente_id',
        'libelle',
        'statut',
    ];

    protected $casts = [
        'statut' => Statut::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    /**
     * Site auquel ce tiroir appartient.
     *
     * Une caisse ne porte pas de `site_id` : il se remonte par le point de
     * vente. L'accesseur existe pour que les policies ne recopient pas cette
     * chaîne — quand un chemin d'accès est écrit à quatre endroits, il finit par
     * être oublié au cinquième.
     */
    public function siteId(): ?int
    {
        return $this->pointDeVente?->site_id;
    }

    /** Sorties d'argent de ce tiroir : coffre, banque, direction. */
    public function versements(): HasMany
    {
        return $this->hasMany(Versement::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SessionCaisse::class);
    }
}
