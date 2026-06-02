<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produit — définition PARTAGÉE (pas de site_id). Version minimale : le CRUD
 * complet arrivera avec le Module Salle. Ici, sert de cible aux fiches
 * techniques.
 */
class Produit extends Model
{
    /** @use HasFactory<ProduitFactory> */
    use HasFactory;

    protected $table = 'produits';

    protected $fillable = [
        'categorie_id',
        'libelle',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'statut' => Statut::class,
    ];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieProduit::class, 'categorie_id');
    }

    public function fichesTechniques(): HasMany
    {
        return $this->hasMany(FicheTechnique::class);
    }

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
