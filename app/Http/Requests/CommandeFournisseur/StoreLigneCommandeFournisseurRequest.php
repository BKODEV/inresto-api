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
                new MatiereAuCatalogue($this->route('commande_fournisseur')->fournisseur_id),
            ],
            // Pas d'unité au contrat : imposée par le catalogue (unité d'achat).
            'quantite_commandee' => ['required', 'numeric', 'gt:0'],
            'prix_unitaire_previsionnel' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
