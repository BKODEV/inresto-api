<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DepenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dépense imputée à un budget de site.
 *
 * Les dépenses de type `achat_fournisseur` sont créées par le ReceptionService
 * à la confirmation d'une réception (une dépense par ligne reçue), pour le
 * montant réel facturé. Les `charge_hors_stock` sont saisies manuellement.
 */
class Depense extends Model
{
    /** @use HasFactory<DepenseFactory> */
    use HasFactory;

    protected $table = 'depenses';

    protected $fillable = [
        'budget_id',
        'reception_id',
        'type',
        'libelle',
        'montant',
        'date_depense',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'date_depense' => 'date',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }
}
