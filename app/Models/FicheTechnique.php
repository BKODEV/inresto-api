<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CarteStatut;
use App\Enums\Statut;
use App\Enums\StatutFicheTechnique;
use Database\Factories\FicheTechniqueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fiche technique d'un produit (définition PARTAGÉE, versionnée).
 *
 * `version`, `statut`, `cout_matiere` et `updated_by` sont pilotés par le
 * FicheTechniqueService (hors fillable). Une modification crée une nouvelle
 * version active et archive l'ancienne (historique figé).
 */
class FicheTechnique extends Model
{
    /** @use HasFactory<FicheTechniqueFactory> */
    use HasFactory;

    protected $table = 'fiches_techniques';

    protected $fillable = [
        'produit_id',
    ];

    protected $attributes = [
        'cout_matiere' => 0,
    ];

    protected $casts = [
        'version' => 'integer',
        'cout_matiere' => 'decimal:2',
        'statut' => StatutFicheTechnique::class,
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function lignesMatiere(): HasMany
    {
        return $this->hasMany(LigneFicheTechnique::class);
    }

    public function lignesSousRecette(): HasMany
    {
        return $this->hasMany(LigneFicheTechniqueSousRecette::class);
    }

    public function estActive(): bool
    {
        return $this->statut === StatutFicheTechnique::Active;
    }

    /**
     * Vrai si le produit de cette fiche figure (ligne carte_produit active) dans
     * au moins une carte ACTIVE. Bloque la suppression de la fiche : la carte en
     * dépend.
     */
    public function estReferenceeDansCarteActive(): bool
    {
        return CarteProduit::query()
            ->where('produit_id', $this->produit_id)
            ->where('statut', Statut::Actif->value)
            ->whereHas('carte', fn ($query) => $query->where('statut', CarteStatut::Active->value))
            ->exists();
    }
}
