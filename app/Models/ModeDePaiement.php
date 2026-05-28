<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Statut;
use Database\Factories\ModeDePaiementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModeDePaiement extends Model
{
    /** @use HasFactory<ModeDePaiementFactory> */
    use HasFactory;

    protected $table = 'modes_de_paiement';

    protected $fillable = [
        'libelle',
        'statut',
    ];

    protected $casts = [
        'statut' => Statut::class,
    ];
}
