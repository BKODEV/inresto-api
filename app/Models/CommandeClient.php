<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use App\Enums\StatutCommandeClient;
use Database\Factories\CommandeClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande client (salle). Le `montant_total` est recalculé par le
 * CommandeClientService à chaque mutation de ligne ; `statut` suit la machine à
 * états (ouverte → envoyee_en_cuisine → servie → encaissee, + annulee).
 */
class CommandeClient extends Model
{
    /** @use HasFactory<CommandeClientFactory> */
    use HasFactory;

    protected $table = 'commandes_client';

    protected $fillable = [
        'table_id',
        'table_numero',
        'session_caisse_id',
        'user_id',
        'reservation_id',
        'appartement_id',
        'appartement_nom',
        'appartement_a_verifier',
        'notes',
        'reference_facture_normalisee',
    ];

    protected $attributes = [
        'statut' => StatutCommandeClient::Ouverte->value,
        'montant_total' => 0,
        'appartement_a_verifier' => false,
    ];

    protected $casts = [
        'appartement_a_verifier' => 'boolean',
        'date_heure' => 'datetime',
        'montant_total' => 'decimal:2',
        'statut' => StatutCommandeClient::class,
        'servie_le' => 'datetime',
        'encaisse_le' => 'datetime',
    ];

    public function table(): BelongsTo
    {
        return $this->belongsTo(TableSalle::class, 'table_id');
    }

    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    /**
     * Qui a clôturé la note. Null pour les commandes encaissées avant
     * l'introduction de la colonne, ou jamais encaissées.
     */
    public function encaissePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encaisse_par');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeClient::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function remises(): HasMany
    {
        return $this->hasMany(Remise::class);
    }

    /**
     * Commande abandonnable : ouverte et n'ayant JAMAIS rien porté.
     *
     * Taper une table crée aussitôt sa commande — c'est ce qui réserve la table
     * et empêche deux serveurs de l'ouvrir en même temps. Défaire ce geste ne
     * doit rien coûter : rien n'a été commandé, rien n'est parti en cuisine,
     * rien n'a bougé en stock. Exiger un motif d'annulation reviendrait à noyer
     * la table `annulations` — la trace qui sert à détecter les fraudes — sous
     * des « erreur de saisie ».
     *
     * Le test porte sur l'existence des lignes, pas sur les lignes ACTIVES :
     * une commande dont tout a été annulé porte déjà des `annulations`, elle a
     * donc une histoire et doit passer par l'annulation motivée.
     */
    public function peutEtreLiberee(): bool
    {
        if (! $this->estOuverte()) {
            return false;
        }

        $lignes = $this->relationLoaded('lignes')
            ? $this->lignes->count()
            : ($this->lignes_count ?? $this->lignes()->count());

        $paiements = $this->paiements_count ?? $this->paiements()->count();

        return $lignes === 0 && $paiements === 0;
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutCommandeClient::Ouverte;
    }

    public function estEnvoyeeEnCuisine(): bool
    {
        return $this->statut === StatutCommandeClient::EnvoyeeEnCuisine;
    }

    public function estServie(): bool
    {
        return $this->statut === StatutCommandeClient::Servie;
    }

    public function estEncaissee(): bool
    {
        return $this->statut === StatutCommandeClient::Encaissee;
    }

    /**
     * Total des lignes actives = Σ (quantite × prix_unitaire). C'est aussi la
     * valeur persistée dans `montant_total`.
     */
    public function montantLignesActives(): float
    {
        return (float) $this->lignes()
            ->where('statut', Statut::Actif->value)
            ->get()
            ->sum(fn (LigneCommandeClient $ligne): float => (float) $ligne->quantite * (float) $ligne->prix_unitaire);
    }

    /**
     * Total des remises appliquées (pourcentage rapporté au total des lignes,
     * ou montant fixe).
     */
    public function totalRemises(): float
    {
        $base = $this->montantLignesActives();

        return (float) $this->remises()->get()->sum(fn (Remise $remise): float => $remise->montantPour($base));
    }

    /**
     * Montant dû = total des lignes actives − total des remises (plancher à 0).
     */
    public function montantDu(): float
    {
        return round(max(0, $this->montantLignesActives() - $this->totalRemises()), 2);
    }

    public function montantPaye(): float
    {
        return round((float) $this->paiements()->sum('montant'), 2);
    }

    public function resteAPayer(): float
    {
        return round($this->montantDu() - $this->montantPaye(), 2);
    }
}
