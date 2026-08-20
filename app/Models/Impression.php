<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeImpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Trace d'impression d'un document client. En ajout seul : une impression est
 * un fait, elle ne se corrige pas.
 */
class Impression extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'imprimable_type',
        'imprimable_id',
    ];

    protected $casts = [
        'type' => TypeImpression::class,
        'date_heure' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function imprimable(): MorphTo
    {
        return $this->morphTo();
    }
}
