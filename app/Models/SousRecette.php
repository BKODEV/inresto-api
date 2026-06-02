<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatutFicheTechnique;
use Database\Factories\SousRecetteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sous-recette : préparation intermédiaire PARTAGÉE entre sites (pas de stock
 * propre). `cout_matiere` est un indicateur cuisine recalculé par le
 * SousRecetteService à partir du PMP moyen multi-site des matières (hors
 * fillable : jamais saisi à la main).
 */
class SousRecette extends Model
{
    /** @use HasFactory<SousRecetteFactory> */
    use HasFactory;

    protected $table = 'sous_recettes';

    protected $fillable = [
        'libelle',
        'quantite_produite',
        'unite_id',
    ];

    protected $attributes = [
        'cout_matiere' => 0,
    ];

    protected $casts = [
        'quantite_produite' => 'decimal:3',
        'cout_matiere' => 'decimal:2',
    ];

    public function unite(): BelongsTo
    {
        return $this->belongsTo(UniteDeMesure::class, 'unite_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneSousRecette::class);
    }

    /**
     * Recalcule `cout_matiere` = Σ (ligne.quantite × PMP moyen de la matière sur
     * tous les sites). Une matière sans aucune ligne de stock compte pour 0.
     */
    public function recalculerCoutMatiere(): void
    {
        $this->loadMissing('lignes');

        $pmpParMatiere = Stock::query()
            ->whereIn('matiere_premiere_id', $this->lignes->pluck('matiere_premiere_id'))
            ->groupBy('matiere_premiere_id')
            ->selectRaw('matiere_premiere_id, AVG(pmp) AS pmp_moyen')
            ->pluck('pmp_moyen', 'matiere_premiere_id');

        $cout = $this->lignes->sum(
            fn (LigneSousRecette $ligne): float => (float) $ligne->quantite * (float) ($pmpParMatiere[$ligne->matiere_premiere_id] ?? 0)
        );

        $this->cout_matiere = round($cout, 2);
        $this->save();
    }

    /**
     * Vrai si au moins une fiche technique ACTIVE référence cette sous-recette
     * (via lignes_fiche_technique_sous_recette). Bloque la suppression.
     */
    public function estUtiliseeDansFicheTechnique(): bool
    {
        return LigneFicheTechniqueSousRecette::query()
            ->where('sous_recette_id', $this->id)
            ->whereHas('ficheTechnique', fn ($query) => $query->where('statut', StatutFicheTechnique::Active->value))
            ->exists();
    }
}
