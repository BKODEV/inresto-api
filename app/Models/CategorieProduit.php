<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CategorieProduitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategorieProduit extends Model
{
    /** @use HasFactory<CategorieProduitFactory> */
    use HasFactory;

    protected $table = 'categories_produit';

    protected $fillable = [
        'libelle',
        'description',
        'ordre',
    ];

    protected $attributes = [
        'ordre' => 0,
    ];

    protected $casts = [
        'ordre' => 'integer',
    ];
}
