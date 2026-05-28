<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\UniteDeMesureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UniteDeMesure extends Model
{
    /** @use HasFactory<UniteDeMesureFactory> */
    use HasFactory;

    protected $table = 'unites_de_mesure';

    protected $fillable = [
        'libelle',
        'symbole',
        'statut',
    ];

    protected $casts = [
        'statut' => Statut::class,
    ];
}
