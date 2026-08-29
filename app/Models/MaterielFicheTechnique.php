<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un matériel nécessaire à la production du plat.
 *
 * Texte libre et non une référence : une braisière, une sonde ou un chinois ne
 * sont pas des matières et n'ont ni stock, ni coût, ni fournisseur. Les
 * inventorier serait un autre module.
 */
class MaterielFicheTechnique extends Model
{
    protected $table = 'materiels_fiche_technique';

    protected $fillable = [
        'ordre',
        'libelle',
    ];

    protected $casts = [
        'ordre' => 'integer',
    ];

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class, 'fiche_technique_id');
    }
}
