<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MouvementType;
use Database\Factories\MouvementStockFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Mouvement de stock — source de vérité de la valorisation du stock.
 *
 * Ces lignes ne sont écrites QUE par le StockService. `quantite` est toujours
 * stockée positive ; c'est `type` (via {@see MouvementType::sens()}) qui porte
 * le sens entrée/sortie.
 */
class MouvementStock extends Model
{
    /** @use HasFactory<MouvementStockFactory> */
    use HasFactory;

    protected $table = 'mouvements_stock';

    /**
     * La table ne porte que `created_at` (et `date_mouvement`), alimentés par
     * défaut côté base. Pas d'`updated_at` : un mouvement est immuable.
     */
    public $timestamps = false;

    protected $fillable = [
        'matiere_premiere_id',
        'site_id',
        'user_id',
        'type',
        'quantite',
        'pmp_au_moment',
        'reference_type',
        'reference_id',
        'note',
    ];

    protected $casts = [
        'type' => MouvementType::class,
        'quantite' => 'decimal:3',
        'pmp_au_moment' => 'decimal:4',
        'date_mouvement' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function matiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class, 'matiere_premiere_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Origine polymorphe du mouvement (réception, ligne de vente, inventaire...).
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Valorisation du mouvement au PMP figé à l'instant T — jamais au PMP
     * courant : c'est ce qui rend l'historique stable dans le temps.
     */
    public function valeur(): float
    {
        return round((float) $this->quantite * (float) $this->pmp_au_moment, 2);
    }
}
