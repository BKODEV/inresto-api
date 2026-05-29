<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CatalogueFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogueFournisseur extends Model
{
    /** @use HasFactory<CatalogueFournisseurFactory> */
    use HasFactory;

    protected $table = 'catalogue_fournisseur';

    protected $fillable = [
        'fournisseur_id',
        'matiere_premiere_id',
        'prix_unitaire',
        'unite_achat_id',
    ];

    protected $casts = [
        'prix_unitaire' => 'decimal:2',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function matierePremiere(): BelongsTo
    {
        return $this->belongsTo(MatierePremiere::class);
    }

    public function uniteAchat(): BelongsTo
    {
        return $this->belongsTo(UniteDeMesure::class, 'unite_achat_id');
    }
}
