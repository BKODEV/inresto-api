<?php

declare(strict_types=1);

namespace App\Http\Requests\PosteCuisine\Concerns;

use App\Enums\Statut;
use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\DB;

/**
 * Règles de rattachement d'un poste (brigade, catégories, poste par défaut),
 * partagées par la création et la mise à jour.
 *
 * Les deux FormRequests ne peuvent pas hériter l'un de l'autre : la création
 * exige un point de vente, la mise à jour l'interdit et doit s'ignorer elle-même
 * sur l'unicité du libellé. Le commun vit donc ici.
 */
trait ValideLeRattachementDuPoste
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function reglesMembres(): array
    {
        return [
            // Un ENSEMBLE que l'écran repose en entier, jamais une liste à
            // laquelle on ajoute — comme les catégories. Absent = inchangé,
            // tableau vide = on détache tout.
            'membres' => ['sometimes', 'array'],
            'membres.*' => ['uuid', 'distinct', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function reglesCategories(): array
    {
        return [
            // Absent = rattachement inchangé ; tableau vide = on détache tout.
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'distinct', 'exists:categories_produit,id'],
            'par_defaut' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->verifierMembres($validator);
            $this->verifierCategories($validator);
            $this->verifierPosteParDefaut($validator);
        });
    }

    /**
     * Un membre doit appartenir au SITE du point de vente du poste.
     *
     * Un `sync` l'accepterait sans broncher : la brigade contiendrait quelqu'un
     * d'un autre établissement, et le service lui serait imputé. Le refus est
     * explicite plutôt que silencieux.
     */
    private function verifierMembres(Validator $validator): void
    {
        $membres = array_values(array_filter((array) $this->input('membres', [])));
        $siteId = $this->siteDuPoste();

        if ($membres === [] || $siteId === null) {
            return;
        }

        $horsSite = User::query()
            ->whereIn('id', $membres)
            ->whereDoesntHave('sites', fn ($query) => $query->where('sites.id', $siteId))
            ->pluck('id')
            ->all();

        foreach ($membres as $index => $membre) {
            if (in_array($membre, $horsSite, true)) {
                $validator->errors()->add(
                    "membres.{$index}",
                    "Cette personne n'est pas rattachée au site de ce point de vente.",
                );
            }
        }
    }

    /**
     * Une catégorie n'est produite que par UN poste du point de vente.
     *
     * À l'écran, deux postes qui voient le même plat se gênent à peine. Sur un
     * bon de cuisine, le plat sortirait sur deux papiers et serait cuit deux
     * fois. Le même rattachement reste possible sur un autre point de vente :
     * « Cocktails » au bar du restaurant, au chaud du rooftop.
     */
    private function verifierCategories(Validator $validator): void
    {
        $categories = array_values(array_filter((array) $this->input('categories', []), 'is_numeric'));
        $pointDeVenteId = $this->pointDeVenteDuPoste();

        if ($categories === [] || $pointDeVenteId === null) {
            return;
        }

        $poste = $this->route('poste_cuisine');

        $dejaPrises = DB::table('categorie_poste_cuisine')
            ->join('postes_cuisine', 'postes_cuisine.id', '=', 'categorie_poste_cuisine.poste_cuisine_id')
            ->where('postes_cuisine.point_de_vente_id', $pointDeVenteId)
            ->whereIn('categorie_poste_cuisine.categorie_produit_id', $categories)
            ->when(
                $poste instanceof PosteCuisine,
                fn ($query) => $query->where('postes_cuisine.id', '!=', $poste->id)
            )
            ->pluck('postes_cuisine.libelle', 'categorie_poste_cuisine.categorie_produit_id');

        foreach ($categories as $index => $categorie) {
            $libelle = $dejaPrises->get((int) $categorie);

            if ($libelle !== null) {
                $validator->errors()->add(
                    "categories.{$index}",
                    "Cette catégorie est déjà produite par le poste « {$libelle} ».",
                );
            }
        }
    }

    /**
     * Le poste par défaut reçoit tout ce que personne d'autre ne produit : il
     * doit exister et rester actif.
     *
     * On ne le retire donc pas « dans le vide » : on en désigne un autre, qui
     * lui retire la charge. Un poste inactif ne peut pas la prendre.
     */
    private function verifierPosteParDefaut(Validator $validator): void
    {
        $poste = $this->route('poste_cuisine');
        $statut = $this->input('statut', $poste instanceof PosteCuisine ? $poste->statut->value : Statut::Actif->value);
        $seraParDefaut = $this->has('par_defaut')
            ? $this->boolean('par_defaut')
            : ($poste instanceof PosteCuisine ? $poste->par_defaut : $this->seraLePremierPoste());

        if ($poste instanceof PosteCuisine && $poste->par_defaut && $this->has('par_defaut') && ! $this->boolean('par_defaut')) {
            $validator->errors()->add(
                'par_defaut',
                'Désignez un autre poste par défaut : celui-ci reçoit ce que personne d\'autre ne produit.',
            );

            return;
        }

        if ($seraParDefaut && $statut === Statut::Inactif->value) {
            $validator->errors()->add(
                'statut',
                'Le poste par défaut doit rester actif : désignez-en un autre avant de le désactiver.',
            );
        }
    }

    /** À la création : le premier poste d'un point de vente en devient le poste par défaut. */
    private function seraLePremierPoste(): bool
    {
        $pointDeVenteId = $this->pointDeVenteDuPoste();

        return $pointDeVenteId !== null
            && ! PosteCuisine::query()->where('point_de_vente_id', $pointDeVenteId)->exists();
    }

    /**
     * Le point de vente du poste : celui fourni à la création, ou celui du poste
     * existant à la mise à jour.
     */
    protected function pointDeVenteDuPoste(): ?int
    {
        $poste = $this->route('poste_cuisine');
        $pointDeVenteId = $poste instanceof PosteCuisine
            ? $poste->point_de_vente_id
            : $this->input('point_de_vente_id');

        return is_numeric($pointDeVenteId) ? (int) $pointDeVenteId : null;
    }

    protected function siteDuPoste(): ?int
    {
        $pointDeVenteId = $this->pointDeVenteDuPoste();

        return $pointDeVenteId === null
            ? null
            : PointDeVente::query()->whereKey($pointDeVenteId)->value('site_id');
    }
}
