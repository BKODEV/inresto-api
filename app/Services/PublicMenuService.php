<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CarteStatut;
use App\Enums\Statut;
use App\Models\Carte;
use App\Models\CarteProduit;
use App\Models\ParametreEtablissement;
use App\Models\PointDeVente;
use App\Models\TableSalle;
use Illuminate\Support\Collection;

/**
 * Résolution du menu consulté par un client depuis le QR de sa table.
 *
 * Lecture seule et sans utilisateur : ce service ne doit jamais écrire ni tracer
 * quoi que ce soit. Le jeton porte la TABLE ; le menu affiché est la carte
 * ACTIVE de son point de vente (une seule carte active à la fois par PDV).
 */
class PublicMenuService
{
    /**
     * Retrouve une table par son jeton public, ou null si le jeton est inconnu.
     *
     * Une table archivée est traitée comme un jeton inconnu : elle n'est plus
     * en salle, et son autocollant QR peut très bien traîner quelque part. Le
     * contrôleur renvoie donc le même 404 « Menu indisponible » que pour un QR
     * erroné, sans révéler que la table a existé.
     */
    public function tableParToken(string $token): ?TableSalle
    {
        return TableSalle::query()
            ->where('public_token', $token)
            ->whereNull('archivee_le')
            ->with('pointDeVente.site')
            ->first();
    }

    /**
     * Assemble tout ce qu'une page menu doit afficher.
     *
     * `carte` est null quand le point de vente n'a aucune carte active : ce
     * n'est pas une erreur (le menu est simplement indisponible pour l'instant),
     * à distinguer d'un jeton inconnu.
     *
     * @return array{
     *     point_de_vente: PointDeVente,
     *     parametre: ParametreEtablissement|null,
     *     carte: Carte|null,
     *     categories: Collection<int, array{libelle: string, produits: Collection<int, CarteProduit>}>
     * }
     */
    public function menuPourTable(TableSalle $table): array
    {
        $pointDeVente = $table->pointDeVente;
        $carte = $this->carteActive($pointDeVente->id);

        return [
            'point_de_vente' => $pointDeVente,
            'parametre' => ParametreEtablissement::where('site_id', $pointDeVente->site_id)->first(),
            'carte' => $carte,
            'categories' => $carte !== null ? $this->categories($carte) : collect(),
        ];
    }

    private function carteActive(int $pointDeVenteId): ?Carte
    {
        return Carte::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->where('statut', CarteStatut::Active->value)
            ->first();
    }

    /**
     * Produits de la carte regroupés par catégorie, dans l'ordre d'affichage.
     *
     * Un produit désactivé SUR LA CARTE, comme un produit désactivé globalement,
     * est exclu : le client ne voit que ce qui est réellement proposé. Les
     * catégories vidées par ce filtrage disparaissent d'elles-mêmes.
     *
     * @return Collection<int, array{libelle: string, produits: Collection<int, CarteProduit>}>
     */
    private function categories(Carte $carte): Collection
    {
        $lignes = CarteProduit::query()
            ->where('carte_id', $carte->id)
            ->where('statut', Statut::Actif->value)
            ->whereHas('produit', fn ($query) => $query->where('statut', Statut::Actif->value))
            ->with('produit.categorie')
            ->orderBy('ordre')
            ->get();

        return $lignes
            ->groupBy(fn (CarteProduit $ligne) => $ligne->produit->categorie_id)
            ->map(fn (Collection $produits) => [
                'libelle' => $produits->first()->produit->categorie?->libelle ?? 'Autres',
                'ordre' => $produits->first()->produit->categorie?->ordre ?? PHP_INT_MAX,
                'produits' => $produits,
            ])
            ->sortBy(['ordre', 'libelle'])
            ->values();
    }
}
