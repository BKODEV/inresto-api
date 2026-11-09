<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeBonCuisine;
use App\Services\BonCuisineService;
use Database\Factories\BonCuisineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bon de cuisine : ce qu'un poste doit produire, ou ne plus produire, pour une
 * note. Une note × un poste × un envoi.
 *
 * FIGÉ à l'émission (libellés, quantités, remarques recopiés) et jamais modifié
 * ensuite : c'est un papier parti en cuisine. Voir {@see BonCuisineService}.
 */
class BonCuisine extends Model
{
    /** @use HasFactory<BonCuisineFactory> */
    use HasFactory;

    protected $table = 'bons_cuisine';

    protected $fillable = [
        'commande_client_id',
        'point_de_vente_id',
        'poste_cuisine_id',
        'poste_libelle',
        'jour',
        'numero',
        'type',
        'partie',
        'parties',
        'user_id',
        'emis_le',
        'imprime_le',
        'nombre_impressions',
        'sans_objet_le',
    ];

    protected $casts = [
        'jour' => 'date',
        'numero' => 'integer',
        'type' => TypeBonCuisine::class,
        'partie' => 'integer',
        'parties' => 'integer',
        'emis_le' => 'datetime',
        'imprime_le' => 'datetime',
        'nombre_impressions' => 'integer',
        'sans_objet_le' => 'datetime',
    ];

    protected $attributes = [
        'nombre_impressions' => 0,
    ];

    /** Encore à la caisse : personne ne l'a sorti, et il a toujours un objet. */
    public function estAImprimer(): bool
    {
        return $this->imprime_le === null && $this->sans_objet_le === null;
    }

    /**
     * Devenu inutile avant d'être imprimé : ses plats ont été servis ou annulés
     * sans lui. Voir {@see BonCuisineService::reviserAttente()}.
     */
    public function estSansObjet(): bool
    {
        return $this->sans_objet_le !== null;
    }

    /** @param  Builder<BonCuisine>  $query */
    public function scopeEnAttente(Builder $query): void
    {
        $query->whereNull('imprime_le')->whereNull('sans_objet_le');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeClient::class, 'commande_client_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    /** Nul quand le point de vente n'a déclaré aucun poste, ou si le poste a été supprimé. */
    public function poste(): BelongsTo
    {
        return $this->belongsTo(PosteCuisine::class, 'poste_cuisine_id');
    }

    /** L'auteur du geste (envoi ou annulation), pas forcément le serveur de la note. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneBonCuisine::class);
    }

    /** Ce qui sort sur le papier : les plats qui ont encore un objet. */
    public function lignesAImprimer(): HasMany
    {
        return $this->lignes()->where('sans_objet', false);
    }
}
