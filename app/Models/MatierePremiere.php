<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\MatierePremiereFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MatierePremiere extends Model
{
    /** @use HasFactory<MatierePremiereFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'matieres_premieres';

    /**
     * Colonnes modifiables via l'API.
     *
     * Volontairement, `stock_courant` et `pmp` ne figurent PAS dans le fillable :
     * ce sont des caches alimentés uniquement par le StockService (mouvements de stock).
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
        'stock_courant' => 0,
        'pmp' => 0,
    ];

    protected $casts = [
        'seuil_alerte_stock' => 'decimal:3',
        'gestion_peremption' => 'boolean',
        'stock_courant' => 'decimal:3',
        'pmp' => 'decimal:2',
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

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
