<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'un contenant devait contenir à la fermeture, et ce qu'on y a constaté.
 *
 * Le contenant, c'est le mode de paiement : le tiroir pour les espèces, le
 * terminal pour la carte, la pochette pour les chèques. Voir la migration
 * `create_rapprochements_caisse_table` pour le raisonnement.
 */
class RapprochementCaisse extends Model
{
    protected $table = 'rapprochements_caisse';

    protected $fillable = [
        'session_caisse_id',
        'mode_de_paiement_id',
        'montant_theorique',
        'montant_constate',
        'ecart',
    ];

    protected $casts = [
        'montant_theorique' => 'decimal:2',
        'montant_constate' => 'decimal:2',
        'ecart' => 'decimal:2',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class, 'session_caisse_id');
    }

    public function modePaiement(): BelongsTo
    {
        return $this->belongsTo(ModeDePaiement::class, 'mode_de_paiement_id');
    }

    /** Personne n'a déclaré ce contenant : on n'invente pas d'écart. */
    public function estRapproche(): bool
    {
        return $this->montant_constate !== null;
    }

    /**
     * Sens de l'écart de ce contenant.
     *
     * La tolérance ne vaut que pour les modes EN TIROIR : elle existe parce
     * qu'avec de la petite monnaie on ne tombe jamais pile. Un terminal de
     * paiement, lui, tombe toujours pile — un écart y est une transaction
     * manquante, pas du bruit.
     *
     * @return 'non_rapproche'|'juste'|'tolere'|'excedent'|'manquant'
     */
    public function sensEcart(float $tolerance = 0.0): string
    {
        if (! $this->estRapproche()) {
            return 'non_rapproche';
        }

        $ecart = round((float) $this->ecart, 2);

        if ($ecart === 0.0) {
            return 'juste';
        }

        $tolere = $this->modePaiement?->compte_en_caisse === true && $tolerance > 0;

        if ($tolere && abs($ecart) <= $tolerance) {
            return 'tolere';
        }

        return $ecart > 0 ? 'excedent' : 'manquant';
    }
}
