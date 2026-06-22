<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnnulationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Journal d'annulation (polymorphe) : une commande entière ou une ligne. `sort`
 * (remise_en_stock | perte) n'est renseigné que pour une ligne déjà déstockée.
 */
class Annulation extends Model
{
    /** @use HasFactory<AnnulationFactory> */
    use HasFactory;

    protected $table = 'annulations';

    protected $fillable = [
        'user_id',
        'sort',
        'motif',
    ];

    protected $casts = [
        'date_heure' => 'datetime',
    ];

    public function annulable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
