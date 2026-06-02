<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneFicheTechniqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne « matière première » d'une fiche technique.
 */
class LigneFicheTechnique extends Model
{
    /** @use HasFactory<LigneFicheTechniqueFactory> */
    use HasFactory;

    protected $table = 'lignes_fiche_technique';

    protected $fillable = [
        'fiche_technique_id',
        'matiere_premiere_id',
        'unite_id',
        'quantite',
    ];

    protected $casts = [
        'quantite' => 'decimal:3',
    ];

    public function ficheTechnique(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class);
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
