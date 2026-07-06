<?php

namespace Database\Seeders;

use App\Models\Fournisseur;
use Illuminate\Database\Seeder;

/**
 * Quelques fournisseurs par défaut pour démarrer les tests réels d'achat et de
 * réception. Données idempotentes (clé naturelle = nom).
 */
class FournisseurSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            [
                'nom' => 'Marché de gros Abidjan',
                'telephone' => '+225 27 20 30 40 50',
                'email' => 'contact@marchegros-abidjan.ci',
                'conditions_paiement' => 'Comptant',
                'delai_livraison_jours' => 1,
            ],
            [
                'nom' => 'Grossiste Épicerie CI',
                'telephone' => '+225 27 21 22 23 24',
                'email' => 'commandes@epicerie-ci.com',
                'conditions_paiement' => '30 jours fin de mois',
                'delai_livraison_jours' => 2,
            ],
            [
                'nom' => 'Boucherie & Volailles du Plateau',
                'telephone' => '+225 07 08 09 10 11',
                'email' => 'ventes@boucherie-plateau.ci',
                'conditions_paiement' => 'Comptant',
                'delai_livraison_jours' => 1,
            ],
        ])->each(fn (array $data) => Fournisseur::query()->firstOrCreate(
            ['nom' => $data['nom']],
            $data,
        ));
    }
}
