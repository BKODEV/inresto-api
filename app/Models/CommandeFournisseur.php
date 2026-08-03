<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutCommandeFournisseur;
use Database\Factories\CommandeFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Commande fournisseur.
 *
 * `numero`, `statut` et `montant_previsionnel` sont gérés exclusivement par le
 * CommandeFournisseurService : volontairement hors `$fillable` pour empêcher
 * leur écriture via mass assignment depuis l'API.
 *
 * Une commande N'ÉCRIT JAMAIS dans le stock : le stock ne bouge qu'à la
 * réception (module dédié).
 */
class CommandeFournisseur extends Model
{
    /** @use HasFactory<CommandeFournisseurFactory> */
    use HasFactory;

    protected $table = 'commandes_fournisseur';

    protected $fillable = [
        'fournisseur_id',
        'date_commande',
        'notes',
    ];

    protected $attributes = [
        'montant_previsionnel' => 0,
    ];

    protected $casts = [
        'date_commande' => 'date',
        'montant_previsionnel' => 'decimal:2',
        'statut' => StatutCommandeFournisseur::class,
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeFournisseur::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class);
    }

    public function estModifiable(): bool
    {
        return $this->statut->estModifiable();
    }

    /**
     * Cumul des quantités reçues par matière, toutes réceptions de la commande
     * confondues et tous sites confondus (une commande peut être éclatée sur
     * plusieurs sites via plusieurs réceptions).
     *
     * Les quantités sont exprimées en unité d'achat, comme les quantités
     * commandées : les deux sont donc directement comparables.
     *
     * @return Collection<int, float> matiere_premiere_id => quantité reçue
     */
    public function quantitesRecuesParMatiere(): Collection
    {
        return LigneReception::query()
            ->whereIn('reception_id', $this->receptions()->select('id'))
            ->selectRaw('matiere_premiere_id, SUM(quantite_recue) AS total')
            ->groupBy('matiere_premiere_id')
            ->pluck('total', 'matiere_premiere_id')
            ->map(fn ($total): float => (float) $total);
    }
}
