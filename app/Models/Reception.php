<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réception de marchandise rattachée à une commande fournisseur.
 *
 * Porte un `site_id` : la cuisine destinataire dont on alimente le stock.
 * `montant_reel` est géré exclusivement par le ReceptionService (hors fillable)
 * et n'a de sens qu'une fois la réception confirmée.
 *
 * La réception est le SEUL point du système qui déclenche une entrée de stock
 * (via le StockService, à la confirmation).
 */
class Reception extends Model
{
    /** @use HasFactory<ReceptionFactory> */
    use HasFactory;

    protected $table = 'receptions';

    protected $fillable = [
        'commande_fournisseur_id',
        'site_id',
        'date_reception',
        'observations',
    ];

    protected $attributes = [
        'montant_reel' => 0,
    ];

    protected $casts = [
        'date_reception' => 'date',
        'montant_reel' => 'decimal:2',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
    }

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
        return $this->hasMany(LigneReception::class);
    }
}
