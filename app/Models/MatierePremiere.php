<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\MatierePremiereFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatierePremiere extends Model
{
    /** @use HasFactory<MatierePremiereFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'matieres_premieres';

    /**
     * Colonnes modifiables via l'API.
     *
     * Définition GLOBALE : la matière première ne porte plus de stock_courant/pmp.
     * Le stock est par site et vit dans la table `stocks` (voir {@see Stock}).
     */
    protected $fillable = [
        'categorie_id',
        'unite_de_mesure_id',
        'libelle',
        'seuil_alerte_stock',
        'gestion_peremption',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'seuil_alerte_stock' => 'decimal:3',
        'gestion_peremption' => 'boolean',
        'statut' => Statut::class,
    ];

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(CategorieMatierePremiere::class, 'categorie_id');
    }

    public function unite(): BelongsTo
    {
        return $this->belongsTo(UniteDeMesure::class, 'unite_de_mesure_id');
    }

    /**
     * Lignes de stock de cette matière, une par site.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
