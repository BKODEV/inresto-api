<?php

namespace Database\Seeders;

use App\Models\UniteDeMesure;
use Illuminate\Database\Seeder;

/**
 * Unités de mesure par défaut. Ce sont des étiquettes (pas de conversion
 * dimensionnelle) : le seul facteur appliqué est `qte_par_unite_achat` à la
 * réception. Données réelles d'initialisation, idempotentes.
 */
class UniteDeMesureSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            ['libelle' => 'Kilogramme', 'symbole' => 'kg'],
            ['libelle' => 'Litre', 'symbole' => 'L'],
            ['libelle' => 'Pièce', 'symbole' => 'pce'],
        ])->each(fn (array $data) => UniteDeMesure::query()->firstOrCreate(
            ['symbole' => $data['symbole']],
            ['libelle' => $data['libelle']],
        ));
    }
}
