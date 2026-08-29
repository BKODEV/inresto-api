<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutSessionCuisine;
use Database\Factories\SessionCuisineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le service d'une brigade — pendant cuisine de la session de caisse, et
 * indépendant d'elle (cf. la migration pour le pourquoi).
 *
 * `ouvert_par` désigne qui a lancé le service ; `brigade` est l'équipe. Les
 * deux ne se confondent pas : le chef ouvre, trois commis produisent.
 */
class SessionCuisine extends Model
{
    /** @use HasFactory<SessionCuisineFactory> */
    use HasFactory;

    protected $table = 'sessions_cuisine';

    protected $fillable = [
        'point_de_vente_id',
        'libelle',
        'notes',
    ];

    protected $casts = [
        'ouvert_le' => 'datetime',
        'ferme_le' => 'datetime',
        'statut' => StatutSessionCuisine::class,
    ];

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }

    public function ouvertPar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ouvert_par');
    }

    public function fermePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ferme_par');
    }

    /** L'équipe qui tient le service. */
    public function brigade(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'session_cuisine_user')->withTimestamps();
    }

    /** Les lignes parties en cuisine pendant ce service. */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeClient::class);
    }

    public function estOuverte(): bool
    {
        return $this->statut === StatutSessionCuisine::Ouverte;
    }
}
