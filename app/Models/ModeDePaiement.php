<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\ModeDePaiementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un moyen de règlement accepté par l'établissement.
 *
 * `compte_en_caisse` dit si cet argent finit dans le tiroir qu'on compte à la
 * fermeture. C'est lui qui rend l'écart de session interprétable : sans cette
 * distinction, un virement gonflait le théorique et creusait un manquant
 * fictif. Voir {@see SessionCaisse::montantEnCaisse()}.
 */
class ModeDePaiement extends Model
{
    /** @use HasFactory<ModeDePaiementFactory> */
    use HasFactory;

    protected $table = 'modes_de_paiement';

    protected $fillable = [
        'libelle',
        'compte_en_caisse',
        'ordre',
        'statut',
    ];

    /**
     * Un mode créé sans précision alimente le tiroir : c'est le cas
     * majoritaire, et le supposer hors tiroir sortirait son argent du théorique
     * de fermeture sans que personne ne l'ait décidé.
     */
    protected $attributes = [
        'compte_en_caisse' => true,
        'ordre' => 0,
        // Le défaut vivait uniquement en base : l'instance renvoyée par une
        // création sans `statut` avait donc `null` en mémoire, et la resource
        // tombait sur `null->value` — POST sans statut renvoyait un 500.
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'compte_en_caisse' => 'boolean',
        'ordre' => 'integer',
        'statut' => Statut::class,
    ];
}
