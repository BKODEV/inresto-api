<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneInventaireFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'inventaire : la quantité physiquement comptée d'une matière.
 *
 * `quantite_theorique` est un indicatif (stock_courant lu) ; `ecart` reste null
 * tant que l'inventaire n'est pas clôturé.
 */
class LigneInventaire extends Model
{
    /** @use HasFactory<LigneInventaireFactory> */
    use HasFactory;

    protected $table = 'lignes_inventaire';

    protected $fillable = [
        'inventaire_id',
        'matiere_premiere_id',
        'quantite_theorique',
        'quantite_physique',
        'ecart',
    ];

    protected $casts = [
        'quantite_theorique' => 'decimal:3',
        'quantite_physique' => 'decimal:3',
        'ecart' => 'decimal:3',
    ];

    // Alias morphologique (`lignes_inventaire`) défini dans AppServiceProvider::boot().

    public function inventaire(): BelongsTo
    {
        return $this->belongsTo(Inventaire::class);
    }

    public function matierePremiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class);
    }
}
