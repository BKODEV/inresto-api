<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TypeBonCuisine;
use App\Services\BonCuisineService;
use Database\Factories\BonCuisineFactory;
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
    ];

    protected $casts = [
        'jour' => 'date',
        'numero' => 'integer',
        'type' => TypeBonCuisine::class,
        'partie' => 'integer',
        'parties' => 'integer',
        'emis_le' => 'datetime',
    ];

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
}
