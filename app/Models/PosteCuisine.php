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
 * catégorie non rattachée reste visible de tous les postes à l'écran.
 *
 * Sur un bon de cuisine, en revanche, une ligne ne va qu'à UN poste : une
 * catégorie ne se rattache donc qu'à un seul poste par point de vente, et ce
 * qui n'est rattaché à personne part au poste PAR DÉFAUT (un par point de
 * vente, toujours actif).
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
        'par_defaut',
        'statut',
    ];

    protected $attributes = [
        'ordre' => 0,
        'par_defaut' => false,
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'ordre' => 'integer',
        'par_defaut' => 'boolean',
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

    /**
     * Les personnes qui tiennent ce poste.
     *
     * L'organigramme du chef, et le seul moyen de savoir qui est « de la
     * cuisine » sur ce point de vente : la table `users` ne porte aucune notion
     * de métier, et les rôles sont des jeux de permissions, pas des postes.
     */
    public function membres(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'poste_cuisine_user',
            'poste_cuisine_id',
            'user_id',
        );
    }

    public function estActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
