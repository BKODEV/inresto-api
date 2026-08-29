<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypePointCritique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qui rend un plat dangereux s'il est mal produit : cuisson à cœur,
 * allergène, chaîne du froid.
 *
 * Séparé des étapes à dessein. Une étape se lit une fois, en séquence ; un
 * point critique doit rester visible pendant TOUTE la production, et se
 * retrouver d'un coup d'œil sur la fiche imprimée.
 */
class PointCritiqueFicheTechnique extends Model
{
    protected $table = 'points_critiques_fiche_technique';

    protected $fillable = [
        'ordre',
        'type',
        'description',
    ];

    protected $casts = [
        'ordre' => 'integer',
        'type' => TypePointCritique::class,
    ];

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class, 'fiche_technique_id');
    }
}
