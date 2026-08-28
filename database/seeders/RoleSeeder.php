<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée / met à jour les 6 rôles système (livrés, non supprimables) avec leur
 * description, leur niveau de sécurité et un jeu de permissions cohérent.
 *
 * Idempotent : relançable sans duplication ni perte. Suppose que les permissions
 * existent déjà (lancer {@see PermissionSeeder} avant).
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->definitions() as $name => $def) {
            /** @var Role $role */
            $role = Role::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                [
                    'description' => $def['description'],
                    'niveau' => Role::NIVEAUX[$name],
                    'is_system' => true,
                ],
            );

            $permissions = $name === Role::SUPER_ADMIN
                ? Permission::query()->where('guard_name', 'api')->get()
                : $def['permissions'];

            $role->syncPermissions($permissions);
        }
    }

    /**
     * @return array<string, array{description: string, permissions: string[]}>
     */
    private function definitions(): array
    {
        return [
            Role::SUPER_ADMIN => [
                'description' => 'Accès total : configuration système, rôles, utilisateurs, tous les sites.',
                'permissions' => [], // remplacé par toutes les permissions
            ],
            'gerant' => [
                'description' => 'Gère le personnel de ses sites, la carte, les tables et consulte tous les rapports.',
                'permissions' => [
                    ...$this->crud('utilisateur'),
                    'role.view',
                    'parametre.view',
                    'site.view',
                    ...$this->crud('point-de-vente'),
                    ...$this->crud('caisse'),
                    'parametre_etablissement.view', 'parametre_etablissement.update',
                    ...$this->crud('categorie-produit'),
                    ...$this->crud('mode-de-paiement'),
                    'unite-de-mesure.view', 'categorie-matiere-premiere.view',
                    ...$this->crud('carte'),
                    ...$this->crud('produit'),
                    ...$this->crud('carte_produit'),
                    ...$this->crud('table'),
                    ...$this->crud('session_caisse'), 'session_caisse.utiliser',
                    'session_caisse.forcer_fermeture',
                    ...$this->crud('commande_client'), 'commande_client.encaisser',
                    'commande_client.marquer_pret',
                    ...$this->crud('poste_cuisine'),
                    'paiement.view', 'paiement.create',
                    'versement.view', 'versement.create',
                    'remise.view', 'remise.create', 'remise.autoriser_au_dela_seuil',
                    'annulation.view', 'annulation.create',
                    'fournisseur.view', 'commande-fournisseur.view', 'reception.view',
                    'budget.view', 'inventaire.view', 'stock.view', 'matiere-premiere.view',
                    'fichetechnique.view', 'sousrecette.view',
                    'reporting.food_cost', 'reporting.ventes', 'reporting.stock',
                    'reporting.budget', 'reporting.dashboard', 'reporting.global',
                    // Journal d'activité : c'est le gérant qui contrôle les
                    // annulations, remises et réimpressions de son personnel.
                    'journal.view',
                ],
            ],
            'chef-cuisine' => [
                'description' => 'Cuisine & stock : recettes, fiches techniques, réceptions, inventaires.',
                'permissions' => [
                    ...$this->crud('matiere-premiere'),
                    ...$this->crud('sousrecette'),
                    ...$this->crud('fichetechnique'),
                    ...$this->crud('inventaire'),
                    ...$this->crud('reception'),
                    ...$this->crud('categorie-matiere-premiere'),
                    'stock.view',
                    'unite-de-mesure.view',
                    'produit.view',
                    'fournisseur.view', 'catalogue-fournisseur.view', 'commande-fournisseur.view',
                    // Écran de production (KDS) : la cuisine voit les commandes
                    // envoyées et annonce ses plats prêts au pass.
                    //
                    // `marquer_pret` et NON `update` : ce dernier lui était donné
                    // faute de mieux, du temps où « servi » était le seul geste
                    // disponible — il lui ouvrait au passage la modification et
                    // le service des notes de salle, qui ne le regardent pas.
                    // Séparer l'annonce du service permet enfin de le lui
                    // retirer. La cuisine annonce, la salle emporte.
                    'commande_client.view', 'commande_client.marquer_pret',
                    // Organiser sa brigade est le métier du chef, pas une
                    // question de paramétrage commercial.
                    ...$this->crud('poste_cuisine'),
                    'reporting.food_cost', 'reporting.stock',
                ],
            ],
            'econome' => [
                'description' => 'Approvisionnement : fournisseurs, commandes, réceptions, budgets, inventaires.',
                'permissions' => [
                    ...$this->crud('fournisseur'),
                    ...$this->crud('catalogue-fournisseur'),
                    ...$this->crud('commande-fournisseur'),
                    ...$this->crud('reception'),
                    ...$this->crud('budget'),
                    ...$this->crud('depense'),
                    ...$this->crud('inventaire'),
                    ...$this->crud('matiere-premiere'),
                    ...$this->crud('categorie-matiere-premiere'),
                    'stock.view',
                    'unite-de-mesure.view',
                    'reporting.budget', 'reporting.stock',
                ],
            ],
            'caissier' => [
                'description' => 'Encaissements, sessions de caisse, paiements.',
                'permissions' => [
                    'session_caisse.view', 'session_caisse.create', 'session_caisse.utiliser',
                    'commande_client.view', 'commande_client.create', 'commande_client.encaisser',
                    'paiement.view', 'paiement.create',
                    'versement.view', 'versement.create',
                    'remise.view', 'remise.create',
                    'annulation.view', 'annulation.create',
                    'table.view', 'carte.view', 'produit.view', 'carte_produit.view',
                    'mode-de-paiement.view',
                ],
            ],
            'serveur' => [
                'description' => 'Prise de commande en salle et envoi en cuisine.',
                'permissions' => [
                    'table.view', 'table.update',
                    'session_caisse.view', 'session_caisse.utiliser',
                    'commande_client.view', 'commande_client.create', 'commande_client.update',
                    // Lecture seule : de quoi filtrer l'écran du pass par poste.
                    'poste_cuisine.view',
                    'carte.view', 'produit.view', 'carte_produit.view',
                ],
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function crud(string $feature): array
    {
        return ["{$feature}.view", "{$feature}.create", "{$feature}.update", "{$feature}.delete"];
    }
}
