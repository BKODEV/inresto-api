<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ParametreEtablissementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paramètres d'établissement d'un site : identité commerciale, logo, coordonnées
 * et mentions légales utilisés pour personnaliser les documents imprimés (PDF).
 *
 * Une seule fiche par site (clé unique `site_id`). Aucune donnée d'établissement
 * n'est codée en dur ailleurs : tout en-tête/pied de document lit cette fiche.
 */
class ParametreEtablissement extends Model
{
    /** @use HasFactory<ParametreEtablissementFactory> */
    use HasFactory;

    protected $table = 'parametres_etablissement';

    protected $fillable = [
        'site_id',
        'nom_commercial',
        'slogan',
        'logo_path',
        'adresse',
        'telephone',
        'email',
        'site_web',
        'raison_sociale',
        'ncc',
        'rccm',
        'regime_fiscal',
        'devise',
        'mentions_pied_page',
    ];

    protected $attributes = [
        'devise' => 'XOF',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
