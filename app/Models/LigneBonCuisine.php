<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un bon de cuisine. Libellé, quantité et remarque sont recopiés de la
 * ligne de commande à l'émission : le bon ne bouge plus une fois parti.
 */
class LigneBonCuisine extends Model
{
    protected $table = 'lignes_bon_cuisine';

    protected $fillable = [
        'bon_cuisine_id',
        'ligne_commande_client_id',
        'produit_libelle',
        'quantite',
        'commentaire',
        'sans_objet',
    ];

    protected $attributes = [
        'sans_objet' => false,
    ];

    protected $casts = [
        'quantite' => 'integer',
        'sans_objet' => 'boolean',
    ];

    public function bon(): BelongsTo
    {
        return $this->belongsTo(BonCuisine::class, 'bon_cuisine_id');
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(LigneCommandeClient::class, 'ligne_commande_client_id');
    }
}
