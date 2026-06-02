<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneFicheTechniqueSousRecetteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne « sous-recette » d'une fiche technique.
 */
class LigneFicheTechniqueSousRecette extends Model
{
    /** @use HasFactory<LigneFicheTechniqueSousRecetteFactory> */
    use HasFactory;

    protected $table = 'lignes_fiche_technique_sous_recette';

    protected $fillable = [
        'fiche_technique_id',
        'sous_recette_id',
        'quantite',
    ];

    protected $casts = [
        'quantite' => 'decimal:3',
    ];

    public function ficheTechnique(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class);
    }

    public function sousRecette(): BelongsTo
    {
        return $this->belongsTo(SousRecette::class);
    }
}
