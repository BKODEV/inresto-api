<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une étape de production, dans l'ordre où on l'exécute.
 *
 * Appartient à UNE version de fiche : une nouvelle version recopie ses étapes,
 * l'archivée garde les siennes. Sans quoi le PDF d'une fiche archivée
 * décrirait une recette qui n'est plus la sienne.
 */
class EtapeFicheTechnique extends Model
{
    protected $table = 'etapes_fiche_technique';

    protected $fillable = [
        'ordre',
        'description',
        'duree_minutes',
    ];

    protected $casts = [
        'ordre' => 'integer',
        'duree_minutes' => 'integer',
    ];

    public function fiche(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class, 'fiche_technique_id');
    }
}
