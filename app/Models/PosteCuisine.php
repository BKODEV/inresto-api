<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\PosteCuisineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Poste de production d'un point de vente : « Entrées froides », « Chaud »…
 *
 * Taxonomie de PRODUCTION, distincte de la taxonomie commerciale que sont les
 * catégories de produit. Les catégories s'y rattachent par un pivot ; une
 * catégorie non rattachée reste visible de tous les postes.
 */
class PosteCuisine extends Model
{
    /** @use HasFactory<PosteCuisineFactory> */
    use HasFactory;

    protected $table = 'postes_cuisine';

    protected $fillable = [
        'point_de_vente_id',
        'libelle',
        'ordre',
        'statut',
    ];

    protected $attributes = [
        'ordre' => 0,
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'ordre' => 'integer',
        'statut' => Statut::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    /** Les catégories de carte que ce poste produit. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            CategorieProduit::class,
            'categorie_poste_cuisine',
            'poste_cuisine_id',
            'categorie_produit_id',
        );
    }

    public function estActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
