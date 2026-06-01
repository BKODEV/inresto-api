<?php

namespace Database\Factories;

use App\Models\MatierePremiere;
use App\Models\Site;
use App\Models\Stock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'matiere_premiere_id' => MatierePremiere::factory(),
            'site_id' => Site::factory(),
            'stock_courant' => 0,
            'pmp' => 0,
        ];
    }
}
