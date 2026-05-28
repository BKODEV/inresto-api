<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarteStatut;
use Database\Factories\CarteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Carte extends Model
{
    /** @use HasFactory<CarteFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'point_de_vente_id',
        'libelle',
        'statut',
    ];

    protected $casts = [
        'statut' => CarteStatut::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }
}
