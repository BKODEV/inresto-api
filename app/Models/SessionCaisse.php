<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSessionCaisse;
use Database\Factories\SessionCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Session de caisse : période entre l'ouverture (fonds initial) et la fermeture
 * (rapprochement réel/théorique). `montant_theorique`, `montant_reel`, `ecart`
 * et `ferme_le` sont figés par le SessionCaisseService à la fermeture.
 */
class SessionCaisse extends Model
{
    /** @use HasFactory<SessionCaisseFactory> */
    use HasFactory;

    protected $table = 'sessions_caisse';

    protected $fillable = [
        'caisse_id',
        'user_id',
        'fonds_initial',
        'notes',
    ];

    protected $attributes = [
        'fonds_initial' => 0,
        'statut' => StatutSessionCaisse::Ouverte->value,
    ];

    protected $casts = [
        'ouvert_le' => 'datetime',
        'ferme_le' => 'datetime',
        'fonds_initial' => 'decimal:2',
        'montant_theorique' => 'decimal:2',
        'montant_reel' => 'decimal:2',
        'ecart' => 'decimal:2',
        'statut' => StatutSessionCaisse::class,
    ];

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutSessionCaisse::Ouverte;
    }

    /**
     * Point d'extension Lot C : paiements encaissés sur cette session.
     * Tant que paiements n'existe pas, aucun paiement n'est rattaché.
     */
    public function montantPaye(): float
    {
        return 0.0;
    }
}
