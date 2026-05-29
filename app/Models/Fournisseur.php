<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\FournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    /** @use HasFactory<FournisseurFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nom',
        'telephone',
        'email',
        'adresse',
        'conditions_paiement',
        'delai_livraison_jours',
        'statut',
    ];

    protected $attributes = [
        'statut' => Statut::Actif->value,
    ];

    protected $casts = [
        'delai_livraison_jours' => 'integer',
        'statut' => Statut::class,
    ];

    public function isActif(): bool
    {
        return $this->statut === Statut::Actif;
    }
}
