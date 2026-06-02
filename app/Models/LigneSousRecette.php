<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneSousRecetteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une sous-recette : une matière première et sa quantité (dans son
 * unité d'usage). Pas de mouvement de stock ici — le déstockage se fera à la
 * vente (lot futur).
 */
class LigneSousRecette extends Model
{
    /** @use HasFactory<LigneSousRecetteFactory> */
    use HasFactory;

    protected $table = 'lignes_sous_recette';

    protected $fillable = [
        'sous_recette_id',
        'matiere_premiere_id',
        'unite_id',
        'quantite',
    ];

    protected $casts = [
        'quantite' => 'decimal:3',
    ];

    public function sousRecette(): BelongsTo
    {
        return $this->belongsTo(SousRecette::class);
    }

    public function matierePremiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class);
    }

    public function unite(): BelongsTo
    {
        return $this->belongsTo(UniteDeMesure::class, 'unite_id');
    }
}
