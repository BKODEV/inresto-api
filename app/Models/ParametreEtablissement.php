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
        'tolerance_ecart_caisse',
        'mentions_pied_page',
    ];

    protected $casts = [
        'tolerance_ecart_caisse' => 'decimal:2',
    ];

    protected $attributes = [
        'devise' => 'XOF',
        // 0 = pas de tolérance, tout écart de fermeture non nul est signalé.
        // Comme pour la devise, le défaut vit ici en plus de la base : sinon
        // l'instance fraîchement créée renvoie null avant d'être relue.
        'tolerance_ecart_caisse' => 0,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
