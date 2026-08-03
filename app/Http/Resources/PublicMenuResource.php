<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CarteProduit;
use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Vue CLIENT du menu : la seule Resource exposée sans authentification.
 *
 * Elle n'étend ni ne réutilise les Resources d'administration, qui exposent des
 * ids internes et des données de gestion. Tout ce qui est ajouté ici devient
 * public : s'y limiter strictement à ce qu'un convive doit lire sur la carte —
 * aucun id (table, PDV, site, produit), aucun statut technique, aucun coût,
 * marge ou niveau de stock.
 */
class PublicMenuResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $menu */
        $menu = $this->resource;
        $parametre = $menu['parametre'];
        $pointDeVente = $menu['point_de_vente'];

        return [
            // Aucune carte active sur ce point de vente : le front affiche un
            // message neutre plutôt qu'une page vide (et surtout pas une 404,
            // réservée aux jetons inconnus).
            'menu_indisponible' => $menu['carte'] === null,
            'etablissement' => [
                'nom' => $parametre?->nom_commercial,
                'slogan' => $parametre?->slogan,
                'logo_url' => MediaUrl::pour($parametre?->logo_path),
                'devise' => $parametre?->devise ?? 'XOF',
            ],
            'point_de_vente' => [
                'nom' => $pointDeVente->nom,
                'type' => $pointDeVente->type->value,
            ],
            'categories' => collect($menu['categories'])
                ->map(fn (array $categorie) => [
                    'libelle' => $categorie['libelle'],
                    'produits' => $this->produits($categorie['produits']),
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, CarteProduit>  $lignes
     * @return array<int, array<string, mixed>>
     */
    private function produits(Collection $lignes): array
    {
        return $lignes
            ->map(fn (CarteProduit $ligne) => [
                'libelle' => $ligne->produit->libelle,
                'description' => $ligne->produit->description,
                'allergenes' => $ligne->produit->allergenes,
                'photo_url' => MediaUrl::pour($ligne->produit->photo),
                // Le prix affiché est celui de la carte, pas du produit : un même
                // produit peut être vendu à des prix différents selon la carte.
                'prix' => (float) $ligne->prix_vente,
            ])
            ->all();
    }
}
