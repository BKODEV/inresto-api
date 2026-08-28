<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PaiementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement encaissé sur une commande client. Le total payé d'une session de
 * caisse en découle (SessionCaisse::montantPaye).
 *
 * Trois montants, trois questions distinctes : `montant` solde la note (c'est la
 * vente), `pourboire` reste en tiroir sans appartenir à l'établissement, et
 * `montant_recu` est le billet tendu, dont se déduit la monnaie à rendre. Rien
 * de tout cela ne s'invente au moment d'imprimer le reçu : tout se saisit au
 * comptoir.
 *
 * Le bénéficiaire d'un pourboire est le serveur de la note (`commande.user_id`).
 */
class Paiement extends Model
{
    /** @use HasFactory<PaiementFactory> */
    use HasFactory;

    protected $table = 'paiements';

    protected $fillable = [
        'commande_client_id',
        'mode_de_paiement_id',
        'user_id',
        'montant',
        'pourboire',
        'montant_recu',
        'reference_externe',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'pourboire' => 'decimal:2',
        'montant_recu' => 'decimal:2',
        'date_heure' => 'datetime',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeClient::class, 'commande_client_id');
    }

    public function modePaiement(): BelongsTo
    {
        return $this->belongsTo(ModeDePaiement::class, 'mode_de_paiement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
