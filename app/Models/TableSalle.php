<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutTable;
use Database\Factories\TableSalleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Table physique d'un point de vente. Le `statut` est piloté par les modules
 * commandes/réservations (Lot C) ; ici, changement manuel uniquement.
 */
class TableSalle extends Model
{
    /** @use HasFactory<TableSalleFactory> */
    use HasFactory;

    protected $table = 'tables_salle';

    protected $fillable = [
        'point_de_vente_id',
        'numero',
        'capacite',
        'statut',
    ];

    protected $attributes = [
        'statut' => StatutTable::Libre->value,
    ];

    protected $casts = [
        'capacite' => 'integer',
        'statut' => StatutTable::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(CommandeClient::class, 'table_id');
    }

    /**
     * Vrai dès qu'au moins une commande client (quel que soit son statut, y
     * compris l'historique) est rattachée à la table. Bloque sa suppression :
     * on préserve la traçabilité des ventes passées comme des commandes en cours.
     */
    public function aDesCommandes(): bool
    {
        return $this->commandes()->exists();
    }
}
