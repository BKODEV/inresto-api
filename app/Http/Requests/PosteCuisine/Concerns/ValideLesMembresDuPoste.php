<?php

declare(strict_types=1);

namespace App\Http\Requests\PosteCuisine\Concerns;

use App\Models\PointDeVente;
use App\Models\PosteCuisine;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;

/**
 * Règles de la brigade d'un poste, partagées par la création et la mise à jour.
 *
 * Les deux FormRequests ne peuvent pas hériter l'un de l'autre : la création
 * exige un point de vente, la mise à jour l'interdit et doit s'ignorer elle-même
 * sur l'unicité du libellé. Le commun vit donc ici.
 */
trait ValideLesMembresDuPoste
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
     * Un membre doit appartenir au SITE du point de vente du poste.
     *
     * Un `sync` l'accepterait sans broncher : la brigade contiendrait quelqu'un
     * d'un autre établissement, et le service lui serait imputé. Le refus est
     * explicite plutôt que silencieux.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
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
        });
    }

    /**
     * Le site du poste : via le point de vente fourni à la création, ou celui du
     * poste existant à la mise à jour.
     */
    protected function siteDuPoste(): ?int
    {
        $poste = $this->route('poste_cuisine');
        $pointDeVenteId = $poste instanceof PosteCuisine
            ? $poste->point_de_vente_id
            : $this->input('point_de_vente_id');

        return $pointDeVenteId === null
            ? null
            : PointDeVente::query()->whereKey($pointDeVenteId)->value('site_id');
    }
}
