<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    protected $table = 'budgets';

    protected $fillable = [
        'point_de_vente_id',
        'mois',
        'montant_alloue',
        'seuil_alerte_75',
        'seuil_alerte_90',
        'seuil_alerte_100',
    ];

    protected $casts = [
        'mois' => 'date',
        'montant_alloue' => 'decimal:2',
        'seuil_alerte_75' => 'boolean',
        'seuil_alerte_90' => 'boolean',
        'seuil_alerte_100' => 'boolean',
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }
}
