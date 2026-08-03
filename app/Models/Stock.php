<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cache de stock pour le couple (matiere_premiere_id, site_id).
 *
 * `stock_courant` et `pmp` sont alimentés UNIQUEMENT par le StockService
 * (à partir des mouvements de stock), jamais écrits en direct ailleurs.
 */
class Stock extends Model
{
    /** @use HasFactory<StockFactory> */
    use HasFactory;

    protected $table = 'stocks';

    protected $fillable = [
        'matiere_premiere_id',
        'site_id',
        'stock_courant',
        'pmp',
    ];

    protected $attributes = [
        'stock_courant' => 0,
        'pmp' => 0,
    ];

    protected $casts = [
        'stock_courant' => 'decimal:3',
        'pmp' => 'decimal:4',
    ];

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class, 'matiere_premiere_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Valeur du stock détenu sur ce site : quantité courante × PMP du site.
     * Le PMP étant propre au couple (matière, site), cette valeur ne se cumule
     * qu'entre sites, jamais avec un PMP « global » qui n'existe pas.
     */
    public function valeur(): float
    {
        return round((float) $this->stock_courant * (float) $this->pmp, 2);
    }

    /**
     * Vrai quand la quantité courante est retombée au niveau du seuil d'alerte
     * de la matière. Un seuil nul ou absent désactive l'alerte.
     *
     * Nécessite la relation `matiere` chargée (sinon une requête par ligne).
     */
    public function estSousSeuil(): bool
    {
        $seuil = (float) ($this->matiere?->seuil_alerte_stock ?? 0);

        return $seuil > 0 && (float) $this->stock_courant <= $seuil;
    }
}
