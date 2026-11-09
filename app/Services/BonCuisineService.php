<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Statut;
use App\Enums\TypeBonCuisine;
use App\Models\BonCuisine;
use App\Models\CommandeClient;
use App\Models\LigneBonCuisine;
use App\Models\LigneCommandeClient;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Émet les bons de cuisine : découpe un envoi (ou une annulation) en un bon par
 * poste concerné, les numérote et les fige.
 *
 * RÈGLE DE ROUTAGE, propre au papier : une ligne va à UN SEUL poste.
 *  - la catégorie du produit rattachée à un poste ACTIF du point de vente → ce poste ;
 *  - sinon (catégorie libre, produit sans catégorie, poste inactif) → le poste
 *    par défaut ;
 *  - aucun poste actif déclaré → un bon unique « Cuisine ».
 *
 * L'écran applique une autre règle (« non rattaché = visible de tous ») et c'est
 * voulu : à l'écran un doublon se voit, sur papier il se cuisine deux fois.
 *
 * Appelé DANS la transaction de l'envoi ou de l'annulation : un bon n'existe
 * que si le geste a eu lieu, et inversement.
 */
class BonCuisineService
{
    /** Libellé du bon unique d'un point de vente qui n'a déclaré aucun poste. */
    public const LIBELLE_SANS_POSTE = 'Cuisine';

    /**
     * Émet les bons d'un tour qui vient de partir en cuisine.
     *
     * Le premier tour d'une note donne des bons « Envoi », les suivants des
     * bons « Ajout » : la table mange déjà, le poste doit le savoir.
     *
     * @param  EloquentCollection<int, LigneCommandeClient>  $lignes
     * @return Collection<int, BonCuisine>
     */
    public function emettreEnvoi(CommandeClient $commande, EloquentCollection $lignes, Carbon $tour, string $userId): Collection
    {
        $type = BonCuisine::query()
            ->where('commande_client_id', $commande->id)
            ->whereIn('type', [TypeBonCuisine::Envoi->value, TypeBonCuisine::Ajout->value])
            ->exists()
            ? TypeBonCuisine::Ajout
            : TypeBonCuisine::Envoi;

        $pointDeVenteId = $this->pointDeVenteDe($commande);
        $routage = $this->routage($pointDeVenteId);

        $lignes->loadMissing('produit');

        $groupes = $lignes
            ->groupBy(fn (LigneCommandeClient $ligne): string => $this->cle($routage($ligne)))
            ->map(fn (Collection $lignesDuPoste): array => [
                'poste' => $routage($lignesDuPoste->first()),
                'lignes' => $lignesDuPoste,
            ]);

        return $this->emettre($commande, $pointDeVenteId, $type, $groupes, $tour, $userId);
    }

    /**
     * Émet les bons d'annulation des lignes retirées après leur départ en cuisine.
     *
     * Seules comptent les lignes parties et pas encore servies : une ligne au
     * brouillon, la cuisine ne l'a jamais vue ; une ligne servie, elle n'a plus
     * rien à arrêter.
     *
     * L'annulation suit le BON D'ORIGINE, pas le routage du moment : c'est le
     * poste qui a reçu le plat qui doit apprendre qu'il ne faut plus le faire,
     * même si la catégorie a changé de poste depuis.
     *
     * @param  EloquentCollection<int, LigneCommandeClient>  $lignes
     * @return Collection<int, BonCuisine>
     */
    public function emettreAnnulation(CommandeClient $commande, EloquentCollection $lignes, string $userId): Collection
    {
        $aAnnoncer = $lignes->filter(
            fn (LigneCommandeClient $ligne): bool => $ligne->estEnvoyee() && ! $ligne->estServie()
        );

        if ($aAnnoncer->isEmpty()) {
            return collect();
        }

        $pointDeVenteId = $this->pointDeVenteDe($commande);
        $routage = $this->routage($pointDeVenteId);
        $origines = $this->bonsDOrigine($aAnnoncer);

        $aAnnoncer->loadMissing('produit');

        $destinations = $aAnnoncer->mapWithKeys(function (LigneCommandeClient $ligne) use ($origines, $routage): array {
            /** @var BonCuisine|null $origine */
            $origine = $origines->get($ligne->id);

            // Ligne envoyée avant l'existence des bons : on la route comme un envoi.
            $destination = $origine === null
                ? $routage($ligne)
                : ['id' => $origine->poste_cuisine_id, 'libelle' => $origine->poste_libelle, 'ordre' => $origine->poste?->ordre ?? 0];

            return [$ligne->id => $destination];
        });

        $groupes = $aAnnoncer
            ->groupBy(fn (LigneCommandeClient $ligne): string => $this->cle($destinations->get($ligne->id)))
            ->map(fn (Collection $lignesDuPoste): array => [
                'poste' => $destinations->get($lignesDuPoste->first()->id),
                'lignes' => $lignesDuPoste,
            ]);

        return $this->emettre($commande, $pointDeVenteId, TypeBonCuisine::Annulation, $groupes, now(), $userId);
    }

    /**
     * Retire de la file ce qui a perdu son objet avant d'être imprimé.
     *
     * Un bon qui attend à la caisse peut être dépassé par le service : le plat
     * a été servi sans lui, ou annulé. L'imprimer ferait cuire une seconde fois
     * ce qui est déjà parti. Rien n'est supprimé — le plat est marqué « sans
     * objet » sur le bon, et le bon quitte la file quand il ne lui reste rien.
     *
     *  - envoi / ajout : un plat est sans objet dès que sa ligne est annulée ou
     *    servie ;
     *  - annulation : un plat est sans objet si le bon qui l'avait envoyé n'a
     *    jamais été imprimé — la cuisine n'a rien à arrêter. Une ligne envoyée
     *    avant l'existence des bons garde son annulation, faute de savoir.
     *
     * Seuls les bons JAMAIS imprimés sont concernés : un papier déjà en cuisine
     * ne se rattrape pas, c'est le bon d'annulation qui s'en charge.
     *
     * Appelée après chaque geste qui peut rendre un bon inutile (service,
     * annulation), et juste avant chaque impression.
     *
     * @param  array<int, int>  $commandeIds
     */
    public function reviserAttente(array $commandeIds): void
    {
        if ($commandeIds === []) {
            return;
        }

        $bons = BonCuisine::query()
            ->with('lignes.ligneCommande')
            ->whereIn('commande_client_id', $commandeIds)
            ->enAttente()
            ->get();

        // Les envois d'abord : une annulation se juge sur le sort du bon d'origine.
        [$annulations, $envois] = $bons->partition(
            fn (BonCuisine $bon): bool => $bon->type === TypeBonCuisine::Annulation
        );

        foreach ($envois as $bon) {
            $this->marquerSansObjet($bon, function (LigneBonCuisine $ligne): bool {
                $ligneCommande = $ligne->ligneCommande;

                return $ligneCommande->statut !== Statut::Actif || $ligneCommande->estServie();
            });
        }

        $origines = $this->bonsDOrigine(
            new EloquentCollection($annulations->flatMap(
                fn (BonCuisine $bon) => $bon->lignes->map(fn (LigneBonCuisine $ligne) => $ligne->ligneCommande)
            )->all())
        );

        foreach ($annulations as $bon) {
            $this->marquerSansObjet($bon, function (LigneBonCuisine $ligne) use ($origines): bool {
                /** @var BonCuisine|null $origine */
                $origine = $origines->get($ligne->ligne_commande_client_id);

                return $origine !== null && $origine->imprime_le === null;
            });
        }
    }

    /**
     * @param  \Closure(LigneBonCuisine): bool  $estSansObjet
     */
    private function marquerSansObjet(BonCuisine $bon, \Closure $estSansObjet): void
    {
        foreach ($bon->lignes as $ligne) {
            if (! $ligne->sans_objet && $estSansObjet($ligne)) {
                $ligne->sans_objet = true;
                $ligne->save();
            }
        }

        if ($bon->lignes->every(fn (LigneBonCuisine $ligne): bool => $ligne->sans_objet)) {
            $bon->sans_objet_le = now();
            $bon->save();
        }
    }

    /**
     * Prend la file « à imprimer » d'un point de vente et la marque imprimée.
     *
     * Les bons sont verrouillés le temps de la lecture : deux clics sur
     * « imprimer tout », ou deux caisses, ne sortent pas deux fois la même file
     * — le second trouve la file vide.
     *
     * @return array{bons: Collection<int, BonCuisine>, duplicatas: array<int, bool>}
     */
    public function prendreAImprimer(int $pointDeVenteId): array
    {
        return DB::transaction(function () use ($pointDeVenteId): array {
            $ids = BonCuisine::query()
                ->where('point_de_vente_id', $pointDeVenteId)
                ->enAttente()
                ->lockForUpdate()
                ->pluck('id');

            $this->reviserAttente($this->commandesDe($ids->all()));

            return $this->marquerImprimes(
                BonCuisine::query()->whereKey($ids)->enAttente()->pluck('id')->all()
            );
        });
    }

    /**
     * Imprime des bons précis — une réimpression, ou les bons d'une note.
     *
     * Un bon déjà sorti revient en DUPLICATA : en cuisine, il ne doit pas être
     * pris pour une nouvelle commande.
     *
     * @param  array<int, int>  $ids
     * @return array{bons: Collection<int, BonCuisine>, duplicatas: array<int, bool>}
     */
    public function imprimer(array $ids): array
    {
        return DB::transaction(function () use ($ids): array {
            $this->reviserAttente($this->commandesDe($ids));

            // Un bon devenu sans objet sans jamais être sorti n'a rien à
            // imprimer : il est écarté. Celui qui a été imprimé reste
            // réimprimable — c'est un papier qui existe en cuisine.
            $imprimables = BonCuisine::query()
                ->whereKey($ids)
                ->where(fn ($query) => $query->whereNotNull('imprime_le')->orWhereNull('sans_objet_le'))
                ->pluck('id')
                ->all();

            return $this->marquerImprimes($imprimables);
        });
    }

    /**
     * @param  array<int, int>  $bonIds
     * @return array<int, int>
     */
    private function commandesDe(array $bonIds): array
    {
        return BonCuisine::query()->whereKey($bonIds)->distinct()->pluck('commande_client_id')->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{bons: Collection<int, BonCuisine>, duplicatas: array<int, bool>}
     */
    private function marquerImprimes(array $ids): array
    {
        $bons = BonCuisine::query()
            ->with(['lignesAImprimer', 'commande.user'])
            ->whereKey($ids)
            ->lockForUpdate()
            ->orderBy('emis_le')
            ->orderBy('numero')
            ->get();

        $duplicatas = [];
        $maintenant = now();

        foreach ($bons as $bon) {
            $duplicatas[$bon->id] = $bon->nombre_impressions > 0;
            $bon->imprime_le ??= $maintenant;
            $bon->nombre_impressions++;
            $bon->save();
        }

        return ['bons' => $bons->toBase(), 'duplicatas' => $duplicatas];
    }

    /**
     * Crée les bons d'un même geste, dans l'ordre des postes, numérotés à la suite.
     *
     * @param  Collection<string, array{poste: array{id: int|null, libelle: string, ordre: int}, lignes: Collection<int, LigneCommandeClient>}>  $groupes
     * @return Collection<int, BonCuisine>
     */
    private function emettre(
        CommandeClient $commande,
        int $pointDeVenteId,
        TypeBonCuisine $type,
        Collection $groupes,
        Carbon $emisLe,
        string $userId,
    ): Collection {
        $groupes = $groupes
            ->sortBy([
                fn (array $a, array $b): int => $a['poste']['ordre'] <=> $b['poste']['ordre'],
                fn (array $a, array $b): int => ($a['poste']['id'] ?? PHP_INT_MAX) <=> ($b['poste']['id'] ?? PHP_INT_MAX),
            ])
            ->values();

        $jour = $emisLe->toDateString();
        $numero = $this->prochainNumero($pointDeVenteId, $jour);
        $parties = $groupes->count();

        return $groupes->map(function (array $groupe, int $index) use ($commande, $pointDeVenteId, $type, $emisLe, $userId, $jour, $numero, $parties): BonCuisine {
            $bon = BonCuisine::create([
                'commande_client_id' => $commande->id,
                'point_de_vente_id' => $pointDeVenteId,
                'poste_cuisine_id' => $groupe['poste']['id'],
                'poste_libelle' => $groupe['poste']['libelle'],
                'jour' => $jour,
                'numero' => $numero + $index,
                'type' => $type,
                'partie' => $index + 1,
                'parties' => $parties,
                'user_id' => $userId,
                'emis_le' => $emisLe,
            ]);

            foreach ($groupe['lignes'] as $ligne) {
                LigneBonCuisine::create([
                    'bon_cuisine_id' => $bon->id,
                    'ligne_commande_client_id' => $ligne->id,
                    'produit_libelle' => $ligne->produit?->libelle ?? 'Produit #'.$ligne->produit_id,
                    'quantite' => $ligne->quantite,
                    'commentaire' => $ligne->commentaire,
                ]);
            }

            return $bon->load('lignes');
        });
    }

    /**
     * Prochain numéro du jour pour le point de vente.
     *
     * Le point de vente est verrouillé le temps de la transaction : deux envois
     * simultanés liraient sinon le même maximum. L'index unique
     * (point_de_vente_id, jour, numero) reste le dernier rempart.
     */
    private function prochainNumero(int $pointDeVenteId, string $jour): int
    {
        PointDeVente::query()->whereKey($pointDeVenteId)->lockForUpdate()->first();

        return (int) BonCuisine::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->whereDate('jour', $jour)
            ->max('numero') + 1;
    }

    private function pointDeVenteDe(CommandeClient $commande): int
    {
        $commande->loadMissing('table');

        return (int) $commande->table->point_de_vente_id;
    }

    /**
     * Le dernier bon d'envoi qui a porté chaque ligne.
     *
     * @param  Collection<int, LigneCommandeClient>  $lignes
     * @return Collection<int, BonCuisine>
     */
    private function bonsDOrigine(Collection $lignes): Collection
    {
        return LigneBonCuisine::query()
            ->whereIn('ligne_commande_client_id', $lignes->pluck('id'))
            ->whereHas('bon', fn ($query) => $query->whereIn('type', [TypeBonCuisine::Envoi->value, TypeBonCuisine::Ajout->value]))
            ->with('bon.poste')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (LigneBonCuisine $ligne): array => [$ligne->ligne_commande_client_id => $ligne->bon]);
    }

    /**
     * La règle de routage du point de vente, construite une fois par geste :
     * renvoie, pour une ligne, le poste qui la produit.
     *
     * @return \Closure(LigneCommandeClient): array{id: int|null, libelle: string, ordre: int}
     */
    private function routage(int $pointDeVenteId): \Closure
    {
        $postes = PosteCuisine::query()
            ->with('categories:id')
            ->where('point_de_vente_id', $pointDeVenteId)
            ->where('statut', Statut::Actif->value)
            ->orderBy('ordre')
            ->orderBy('id')
            ->get();

        /** @var array<int, PosteCuisine> $parCategorie */
        $parCategorie = [];
        foreach ($postes as $poste) {
            foreach ($poste->categories as $categorie) {
                $parCategorie[$categorie->id] ??= $poste;
            }
        }

        // Le poste par défaut est garanti par le paramétrage ; s'il manque malgré
        // tout (données modifiées à la main), le premier poste actif le remplace
        // plutôt que de perdre le plat.
        $parDefaut = $postes->firstWhere('par_defaut', true) ?? $postes->first();

        return function (LigneCommandeClient $ligne) use ($parCategorie, $parDefaut): array {
            $categorieId = $ligne->produit?->categorie_id;
            $poste = ($categorieId === null ? null : ($parCategorie[$categorieId] ?? null)) ?? $parDefaut;

            return $poste === null
                ? ['id' => null, 'libelle' => self::LIBELLE_SANS_POSTE, 'ordre' => 0]
                : ['id' => $poste->id, 'libelle' => $poste->libelle, 'ordre' => $poste->ordre];
        };
    }

    /**
     * @param  array{id: int|null, libelle: string, ordre: int}  $poste
     */
    private function cle(array $poste): string
    {
        return ($poste['id'] ?? 'sans').'|'.$poste['libelle'];
    }
}
