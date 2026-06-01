<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LigneCommandeFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommandeFournisseur extends Model
{
    /** @use HasFactory<LigneCommandeFournisseurFactory> */
    use HasFactory;

    protected $table = 'lignes_commande_fournisseur';

    protected $fillable = [
        'commande_fournisseur_id',
        'matiere_premiere_id',
        'unite_id',
        'quantite_commandee',
        'prix_unitaire_previsionnel',
    ];

    protected $casts = [
        'quantite_commandee' => 'decimal:3',
        'prix_unitaire_previsionnel' => 'decimal:4',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
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
     * Sous-total prévisionnel de la ligne (quantité × prix unitaire).
     */
    public function sousTotal(): float
    {
        return round((float) $this->quantite_commandee * (float) $this->prix_unitaire_previsionnel, 2);
    }
}
