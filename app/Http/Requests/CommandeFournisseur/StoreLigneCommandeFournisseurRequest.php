<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeFournisseur;

use App\Rules\MatiereAuCatalogue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLigneCommandeFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('commande_fournisseur'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'matiere_premiere_id' => [
                'required',
                'integer',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
                // Une matière ne peut figurer qu'une fois par commande.
                Rule::unique('lignes_commande_fournisseur', 'matiere_premiere_id')
                    ->where('commande_fournisseur_id', $this->route('commande_fournisseur')->id),
                new MatiereAuCatalogue($this->route('commande_fournisseur')->fournisseur_id),
            ],
            // Pas d'unité au contrat : imposée par le catalogue (unité d'achat).
            'quantite_commandee' => ['required', 'numeric', 'gt:0'],
            'prix_unitaire_previsionnel' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matiere_premiere_id.unique' => 'Cet article est déjà présent dans la commande : modifiez la quantité de la ligne existante.',
        ];
    }
}
