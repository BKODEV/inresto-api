<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\LigneCommandeClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'une commande client. `prix_unitaire` est figé au prix de la carte au
 * moment de la vente ; `fiche_technique_id` est figé au déstockage (« servie »).
 */
class LigneCommandeClient extends Model
{
    /** @use HasFactory<LigneCommandeClientFactory> */
    use HasFactory;

    protected $table = 'lignes_commande_client';

    protected $fillable = [
        'commande_client_id',
        'produit_id',
        'fiche_technique_id',
        'quantite',
        'prix_unitaire',
        'commentaire',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire' => 'decimal:2',
        'statut' => Statut::class,
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeClient::class, 'commande_client_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function ficheTechnique(): BelongsTo
    {
        return $this->belongsTo(FicheTechnique::class);
    }

    public function estActive(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
