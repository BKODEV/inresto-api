<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointDeVenteType;
use App\Enums\Statut;
use Database\Factories\PointDeVenteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PointDeVente extends Model
{
    protected $table = 'points_de_vente';

    /** @use HasFactory<PointDeVenteFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'nom',
        'type',
        'description',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'type' => PointDeVenteType::class,
        'statut' => Statut::class,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function caisses(): HasMany
    {
        return $this->hasMany(Caisse::class);
    }

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
