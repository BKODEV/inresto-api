<?php

namespace App\Providers;

use App\Models\LigneInventaire;
use App\Models\LigneReception;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alias morphologiques stables pour `mouvements_stock.reference_type`,
        // indépendants du namespace des classes. Map NON enforcée : les autres
        // relations polymorphes (ex. tokenable de Sanctum) gardent leur classe.
        Relation::morphMap([
            'lignes_reception' => LigneReception::class,
            'lignes_inventaire' => LigneInventaire::class,
        ]);
    }
}
