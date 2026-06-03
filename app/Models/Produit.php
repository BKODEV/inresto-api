<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use App\Enums\StatutFicheTechnique;
use Database\Factories\ProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produit — définition PARTAGÉE (pas de site_id). Le prix de vente n'est pas
 * porté ici mais sur le pivot carte_produit (un produit peut avoir des prix
 * différents selon la carte). Sert aussi de cible aux fiches techniques.
 */
class Produit extends Model
{
    /** @use HasFactory<ProduitFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'produits';

    protected $fillable = [
        'categorie_id',
        'libelle',
        'description',
        'est_menu',
        'photo',
        'allergenes',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
        'est_menu' => false,
    ];

    protected $casts = [
        'est_menu' => 'boolean',
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

    public function cartesProduits(): HasMany
    {
        return $this->hasMany(CarteProduit::class);
    }

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }

    /**
     * Vrai si ce produit est référencé par au moins une fiche technique ACTIVE.
     * Garde-fou de suppression : une recette en production en dépend.
     */
    public function estReferenceeDansFicheActive(): bool
    {
        return $this->fichesTechniques()
            ->where('statut', StatutFicheTechnique::Active->value)
            ->exists();
    }
}
