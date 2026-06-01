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
}
