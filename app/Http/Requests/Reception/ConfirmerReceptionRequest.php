<?php

declare(strict_types=1);

namespace App\Http\Requests\Reception;

use App\Models\Reception;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfirmerReceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Reception $reception */
        $reception = $this->route('reception');

        return $this->user()->can('update', $reception);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.matiere_premiere_id' => [
                'required',
                'integer',
                // Une matière ne peut être reçue qu'une fois par réception :
                // deux lignes produiraient deux mouvements de stock et deux
                // dépenses pour la même livraison (PMP et budget faussés).
                'distinct',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
            ],
            // Pas d'unité au contrat : imposée par le catalogue (unité d'achat),
            // celle-là même qui porte le facteur de conversion du PMP.
            'lignes.*.quantite_recue' => ['required', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire_reel' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.*.matiere_premiere_id.distinct' => 'Cet article est déjà présent dans la réception : cumulez les quantités sur une seule ligne.',
        ];
    }

    /**
     * Chaque ligne reçue doit référencer une matière présente sur la commande.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Reception $reception */
            $reception = $this->route('reception');
            $matieresCommande = $reception->commande
                ->lignes()
                ->pluck('matiere_premiere_id')
                ->all();

            foreach ((array) $this->input('lignes', []) as $index => $ligne) {
                $matiereId = (int) ($ligne['matiere_premiere_id'] ?? 0);

                if (! in_array($matiereId, $matieresCommande, true)) {
                    $validator->errors()->add(
                        "lignes.{$index}.matiere_premiere_id",
                        'Cette matière ne figure pas sur la commande réceptionnée.',
                    );
                }
            }
        });
    }
}
