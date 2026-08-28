<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DestinationVersement;
use Database\Factories\VersementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Argent sorti du tiroir : versement au coffre, à la banque, à la direction.
 *
 * Deux moments, deux effets — voir la migration `create_versements_table` :
 * pendant le service il se retranche du théorique de la session, après la
 * fermeture il explique l'écart d'ouverture de la suivante.
 */
class Versement extends Model
{
    /** @use HasFactory<VersementFactory> */
    use HasFactory;

    protected $table = 'versements';

    protected $fillable = [
        'caisse_id',
        'session_caisse_id',
        'beneficiaire_id',
        'montant',
        'apres_fermeture',
        'destination',
        'remis_a',
        'reference',
        'notes',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'apres_fermeture' => 'boolean',
        'destination' => DestinationVersement::class,
        'date_heure' => 'datetime',
    ];

    public function caisse(): BelongsTo
    {
        return $this->belongsTo(Caisse::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class, 'session_caisse_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Celui dont ce versement éteint la dette — un serveur qu'on paie de ses
     * pourboires. Distinct de `remis_a`, qui dit qui a EMPORTÉ l'argent : une
     * société de collecte emporte sans qu'on lui doive rien.
     */
    public function beneficiaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiaire_id');
    }

    /** Sorti pendant que la caisse tournait, par opposition à après sa clôture. */
    public function estEnService(): bool
    {
        return ! $this->apres_fermeture;
    }
}
