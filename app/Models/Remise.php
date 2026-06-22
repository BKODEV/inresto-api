<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeRemise;
use Database\Factories\RemiseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remise appliquée à une commande : pourcentage (sur le total des lignes) ou
 * montant fixe. Plusieurs remises s'accumulent.
 */
class Remise extends Model
{
    /** @use HasFactory<RemiseFactory> */
    use HasFactory;

    protected $table = 'remises';

    protected $fillable = [
        'commande_client_id',
        'user_id',
        'type',
        'valeur',
        'motif',
    ];

    protected $casts = [
        'type' => TypeRemise::class,
        'valeur' => 'decimal:2',
        'date_heure' => 'datetime',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeClient::class, 'commande_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Montant de la remise rapporté à une base (total des lignes actives).
     */
    public function montantPour(float $base): float
    {
        return $this->type === TypeRemise::Pourcentage
            ? $base * (float) $this->valeur / 100
            : (float) $this->valeur;
    }
}
