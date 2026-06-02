<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneReceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une réception : une matière reçue, dans son unité d'ACHAT.
 *
 * `quantite_recue` et `prix_unitaire_reel` sont exprimés en unité d'achat (tels
 * que sur la facture). La conversion vers l'unité de stockage est faite par le
 * ReceptionService avant l'appel au StockService.
 */
class LigneReception extends Model
{
    /** @use HasFactory<LigneReceptionFactory> */
    use HasFactory;

    protected $table = 'lignes_reception';

    /**
     * Type morphologique stable stocké dans `mouvements_stock.reference_type`,
     * indépendant du namespace de la classe.
     */
    protected $morphClass = 'lignes_reception';

    protected $fillable = [
        'reception_id',
        'matiere_premiere_id',
        'unite_id',
        'quantite_recue',
        'prix_unitaire_reel',
    ];

    protected $casts = [
        'quantite_recue' => 'decimal:3',
        'prix_unitaire_reel' => 'decimal:4',
    ];

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function matierePremiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class);
    }

    public function unite(): BelongsTo
    {
        return $this->belongsTo(UniteDeMesure::class, 'unite_id');
    }

    /**
     * Sous-total réel facturé de la ligne (quantité reçue × prix unitaire réel),
     * en unité d'achat.
     */
    public function sousTotal(): float
    {
        return round((float) $this->quantite_recue * (float) $this->prix_unitaire_reel, 2);
    }
}
