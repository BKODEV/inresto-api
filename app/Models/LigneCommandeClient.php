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
        'envoyee_le' => 'datetime',
        'prete_le' => 'datetime',
        'servie_le' => 'datetime',
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

    /**
     * Partie en cuisine. `envoyee_le` sert aussi d'identifiant de tour : les
     * lignes d'un même envoi partagent son horodatage.
     */
    public function estEnvoyee(): bool
    {
        return $this->envoyee_le !== null;
    }

    /**
     * Annoncée prête au pass par la cuisine, pas encore emportée.
     *
     * Signal de cuisine, donc RÉVERSIBLE : un plat annoncé trop tôt se dévalide.
     * C'est pour cela qu'aucun mouvement de stock n'y est accroché — ils
     * battraient au rythme des corrections du pass. Le stock ne bouge qu'au
     * service, voir {@see estServie()}.
     */
    public function estPrete(): bool
    {
        return $this->prete_le !== null;
    }

    /** Servie et déstockée. Empêche un second service de redéstocker. */
    public function estServie(): bool
    {
        return $this->servie_le !== null;
    }

    /**
     * Ligne encore au brouillon : jamais partie en cuisine, donc sans
     * conséquence — elle se retire sans motif d'annulation.
     */
    public function estRetirableSansMotif(): bool
    {
        return $this->envoyee_le === null;
    }
}
