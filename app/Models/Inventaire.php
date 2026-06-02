<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutInventaire;
use Database\Factories\InventaireFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventaire physique d'un site. `statut`, `valeur_totale_stock` et `user_id`
 * sont pilotés par l'InventaireService (hors fillable).
 *
 * La clôture est le seul moment où l'inventaire déclenche des corrections de
 * stock (via StockService) — jamais pendant la saisie.
 */
class Inventaire extends Model
{
    /** @use HasFactory<InventaireFactory> */
    use HasFactory;

    protected $table = 'inventaires';

    protected $fillable = [
        'site_id',
        'date_inventaire',
        'notes',
    ];

    protected $attributes = [
        'valeur_totale_stock' => 0,
    ];

    protected $casts = [
        'date_inventaire' => 'date',
        'valeur_totale_stock' => 'decimal:2',
        'statut' => StatutInventaire::class,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneInventaire::class);
    }

    public function estEnCours(): bool
    {
        return $this->statut === StatutInventaire::EnCours;
    }
}
