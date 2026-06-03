<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\CarteProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot carte_produit : association d'un produit à une carte avec son prix de
 * vente propre. Le `statut` permet de retirer un produit d'une carte active
 * sans casser l'historique (désactivation plutôt que suppression).
 */
class CarteProduit extends Model
{
    /** @use HasFactory<CarteProduitFactory> */
    use HasFactory;

    protected $table = 'carte_produit';

    protected $fillable = [
        'carte_id',
        'produit_id',
        'prix_vente',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'prix_vente' => 'decimal:2',
        'statut' => Statut::class,
    ];

    public function carte(): BelongsTo
    {
        return $this->belongsTo(Carte::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
