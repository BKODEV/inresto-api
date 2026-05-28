<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategorieMatierePremiereFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategorieMatierePremiere extends Model
{
    /** @use HasFactory<CategorieMatierePremiereFactory> */
    use HasFactory;

    protected $table = 'categories_matiere_premiere';

    protected $fillable = [
        'libelle',
        'description',
    ];
}
