<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Source unique des permissions de l'application : nom technique → libellé humain,
 * groupé par module.
 *
 * - Le `PermissionSeeder` crée les permissions à partir de `allPermissionNames()`.
 * - L'endpoint `GET /permissions` expose `grouped()` pour l'écran de rôles.
 *
 * Les permissions sont définies ICI (dans le code) : l'administrateur les ASSIGNE
 * aux rôles, il n'en crée jamais.
 */
final class PermissionCatalog
{
    /**
     * @return array<string, array<string, string>> module → [permission => libellé]
     */
    public static function groups(): array
    {
        return [
            'Utilisateurs & Rôles' => [
                ...self::crud('utilisateur', 'les utilisateurs'),
                ...self::crud('role', 'les rôles'),
                // Lecture seule : le journal agrège des traces déjà écrites par
                // les modules (annulations, remises, encaissements, impressions).
                // Rien à créer ni modifier, donc pas de crud().
                'journal.view' => "Consulter le journal d'activité",
            ],
            'Configuration' => [
                // Ouvre la section Paramètres dans son ensemble, indépendamment
                // des permissions de chaque écran qu'elle contient. Permet au
                // super-admin de déléguer la configuration sans céder son rôle.
                'parametre.view' => 'Accéder à la section Paramètres',
                ...self::crud('site', 'les sites'),
                ...self::crud('parametre_etablissement', "les paramètres d'établissement"),
                ...self::crud('point-de-vente', 'les points de vente'),
                ...self::crud('caisse', 'les caisses'),
                ...self::crud('categorie-produit', 'les catégories de produits'),
                ...self::crud('categorie-matiere-premiere', 'les catégories de matières premières'),
                ...self::crud('unite-de-mesure', 'les unités de mesure'),
                ...self::crud('mode-de-paiement', 'les modes de paiement'),
            ],
            'Achats & Budget' => [
                ...self::crud('fournisseur', 'les fournisseurs'),
                ...self::crud('catalogue-fournisseur', 'les catalogues fournisseur'),
                ...self::crud('commande-fournisseur', 'les commandes fournisseur'),
                ...self::crud('reception', 'les réceptions'),
                ...self::crud('budget', 'les budgets'),
                ...self::crud('depense', 'les dépenses'),
            ],
            'Cuisine & Stock' => [
                ...self::crud('matiere-premiere', 'les matières premières'),
                ...self::crud('sousrecette', 'les sous-recettes'),
                ...self::crud('fichetechnique', 'les fiches techniques'),
                ...self::crud('inventaire', 'les inventaires'),
                // Lecture seule : le stock est dérivé des mouvements, seul le
                // StockService l'écrit. Pas de CRUD, donc pas de crud().
                'stock.view' => "Consulter l'état du stock",
            ],
            'Salle & Vente' => [
                ...self::crud('carte', 'les cartes'),
                ...self::crud('produit', 'les produits'),
                ...self::crud('carte_produit', 'les produits des cartes'),
                ...self::crud('table', 'les tables de salle'),
                ...self::crud('session_caisse', 'les sessions de caisse'),
                'session_caisse.utiliser' => 'Utiliser une session de caisse',
                ...self::crud('commande_client', 'les commandes client'),
                'commande_client.encaisser' => 'Encaisser une commande client',
                'session_caisse.forcer_fermeture' => 'Fermer une caisse sans que tout soit compté',
                'versement.view' => 'Consulter les versements de caisse',
                'versement.create' => 'Sortir de l\'argent du tiroir (coffre, banque, direction)',
                'paiement.view' => 'Consulter les paiements',
                'paiement.create' => 'Enregistrer des paiements',
                // Volontairement absente de tous les rôles par défaut : c'est à
                // la direction de décider si un serveur suit ses propres
                // pourboires, et elle l'attribue ou la retire quand elle veut.
                'pourboire.suivre_les_siens' => 'Suivre ses propres pourboires',
                'remise.view' => 'Consulter les remises',
                'remise.create' => 'Appliquer des remises',
                'remise.autoriser_au_dela_seuil' => 'Autoriser une remise au-delà du seuil',
                'annulation.view' => 'Consulter les annulations',
                'annulation.create' => 'Annuler des commandes ou des lignes',
            ],
            'Pilotage / Reporting' => [
                'reporting.food_cost' => 'Reporting — food cost',
                'reporting.ventes' => 'Reporting — ventes',
                'reporting.stock' => 'Reporting — stock',
                'reporting.budget' => 'Reporting — budget',
                'reporting.dashboard' => 'Tableau de bord (synthèse transversale)',
                'reporting.global' => 'Reporting — accès à tous les sites',
            ],
        ];
    }

    /**
     * Liste à plat de tous les noms de permission (pour le seeding).
     *
     * @return string[]
     */
    public static function allPermissionNames(): array
    {
        $names = [];

        foreach (self::groups() as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Catalogue groupé prêt pour l'API : [{module, permissions: [{name, libelle}]}].
     *
     * @return array<int, array{module: string, permissions: array<int, array{name: string, libelle: string}>}>
     */
    public static function grouped(): array
    {
        $result = [];

        foreach (self::groups() as $module => $permissions) {
            $entries = [];

            foreach ($permissions as $name => $libelle) {
                $entries[] = ['name' => $name, 'libelle' => $libelle];
            }

            $result[] = ['module' => $module, 'permissions' => $entries];
        }

        return $result;
    }

    public static function humanLabel(string $permission): string
    {
        foreach (self::groups() as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission];
            }
        }

        return $permission;
    }

    /**
     * Les 4 actions CRUD standard avec leur libellé humain.
     *
     * @return array<string, string>
     */
    private static function crud(string $feature, string $cible): array
    {
        return [
            "{$feature}.view" => "Consulter {$cible}",
            "{$feature}.create" => "Créer {$cible}",
            "{$feature}.update" => "Modifier {$cible}",
            "{$feature}.delete" => "Supprimer {$cible}",
        ];
    }
}
