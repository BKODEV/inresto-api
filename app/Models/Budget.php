<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    protected $table = 'budgets';

    protected $fillable = [
        'site_id',
        'mois',
        'montant_alloue',
        'seuil_alerte_75',
        'seuil_alerte_90',
        'seuil_alerte_100',
    ];

    protected $casts = [
        'mois' => 'date',
        'montant_alloue' => 'decimal:2',
        'seuil_alerte_75' => 'boolean',
        'seuil_alerte_90' => 'boolean',
        'seuil_alerte_100' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function depenses(): HasMany
    {
        return $this->hasMany(Depense::class);
    }

    /**
     * Montant total consommé = somme des dépenses du budget (réceptions +
     * charges manuelles). Privilégie un agrégat préchargé (`withSum`) ou la
     * relation chargée pour éviter le N+1, sinon interroge la base.
     */
    public function montantConsomme(): float
    {
        if ($this->depenses_sum_montant !== null) {
            return (float) $this->depenses_sum_montant;
        }

        if ($this->relationLoaded('depenses')) {
            return (float) $this->depenses->sum('montant');
        }

        return (float) $this->depenses()->sum('montant');
    }

    public function montantRestant(): float
    {
        return round((float) $this->montant_alloue - $this->montantConsomme(), 2);
    }

    /**
     * Pourcentage consommé arrondi à 2 décimales. Retourne 0 si l'enveloppe
     * allouée est nulle (la situation est alors portée par {@see niveauAlerte}).
     */
    public function pourcentageConsomme(): float
    {
        $alloue = (float) $this->montant_alloue;

        if ($alloue <= 0) {
            return 0.0;
        }

        return round($this->montantConsomme() / $alloue * 100, 2);
    }

    /**
     * Niveau d'alerte calculé à la volée (aucun effet de bord). Les booléens
     * `seuil_alerte_*` sont des interrupteurs de surveillance : un palier
     * désactivé ne remonte jamais, même franchi. Premier palier actif et
     * franchi (par ordre décroissant) gagne.
     *
     * @return 'depassement'|'seuil_90'|'seuil_75'|'aucun'
     */
    public function niveauAlerte(): string
    {
        $consomme = $this->montantConsomme();
        $alloue = (float) $this->montant_alloue;

        if ($alloue <= 0) {
            return $consomme > 0 ? 'depassement' : 'aucun';
        }

        $pourcentage = $consomme / $alloue * 100;

        return match (true) {
            $pourcentage >= 100 && $this->seuil_alerte_100 => 'depassement',
            $pourcentage >= 90 && $this->seuil_alerte_90 => 'seuil_90',
            $pourcentage >= 75 && $this->seuil_alerte_75 => 'seuil_75',
            default => 'aucun',
        };
    }
}
