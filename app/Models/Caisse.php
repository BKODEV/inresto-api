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
