<?php

declare(strict_types=1);

namespace App\Http\Requests\CatalogueFournisseur;

use App\Enums\StatutCommandeFournisseur;
use App\Models\CatalogueFournisseur;
use App\Models\Fournisseur;
use App\Models\LigneCommandeFournisseur;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SyncCatalogueFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CatalogueFournisseur::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lignes' => ['present', 'array'],
            'lignes.*.matiere_premiere_id' => ['required', 'integer', 'exists:matieres_premieres,id', 'distinct'],
            'lignes.*.prix_unitaire' => ['required', 'numeric', 'min:0'],
            'lignes.*.unite_achat_id' => ['required', 'integer', 'exists:unites_de_mesure,id'],
            'lignes.*.qte_par_unite_achat' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.*.matiere_premiere_id.distinct' => 'Une même matière première ne peut figurer deux fois dans le catalogue.',
        ];
    }

    /**
     * Garde-fou : le catalogue porte le facteur de conversion relu à la
     * réception. Retirer une matière encore rattachée à une commande en attente
     * de réception rendrait cette réception impossible. On bloque donc tout
     * retrait tant qu'une telle commande existe pour ce fournisseur.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Structure du payload invalide : les règles de base l'ont déjà signalé.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Fournisseur $fournisseur */
            $fournisseur = $this->route('fournisseur');

            $matieresConservees = collect($this->input('lignes', []))
                ->pluck('matiere_premiere_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $matieresRetirees = CatalogueFournisseur::query()
                ->where('fournisseur_id', $fournisseur->id)
                ->whereNotIn('matiere_premiere_id', $matieresConservees)
                ->pluck('matiere_premiere_id');

            if ($matieresRetirees->isEmpty()) {
                return;
            }

            $bloquees = LigneCommandeFournisseur::query()
                ->whereIn('matiere_premiere_id', $matieresRetirees)
                ->whereHas('commande', fn ($query) => $query
                    ->where('fournisseur_id', $fournisseur->id)
                    ->whereIn('statut', StatutCommandeFournisseur::enAttenteDeReception()))
                ->with('matierePremiere:id,libelle')
                ->get()
                ->pluck('matierePremiere.libelle')
                ->unique()
                ->values();

            if ($bloquees->isNotEmpty()) {
                $validator->errors()->add(
                    'lignes',
                    'Impossible de retirer du catalogue une matière rattachée à une commande en attente de réception : '.$bloquees->implode(', ').'.'
                );
            }
        });
    }
}
