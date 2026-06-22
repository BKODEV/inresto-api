<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeFournisseur;

use App\Models\CommandeFournisseur;
use App\Rules\MatiereAuCatalogue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommandeFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CommandeFournisseur::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fournisseurId = $this->input('fournisseur_id') !== null
            ? (int) $this->input('fournisseur_id')
            : null;

        return [
            'fournisseur_id' => ['required', 'integer', 'exists:fournisseurs,id'],
            'date_commande' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'lignes' => ['sometimes', 'array'],
            'lignes.*.matiere_premiere_id' => [
                'required_with:lignes',
                'integer',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
                new MatiereAuCatalogue($fournisseurId),
            ],
            'lignes.*.unite_id' => [
                'required_with:lignes',
                'integer',
                Rule::exists('unites_de_mesure', 'id')->where('statut', 'actif'),
            ],
            'lignes.*.quantite_commandee' => ['required_with:lignes', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire_previsionnel' => ['required_with:lignes', 'numeric', 'gt:0'],
        ];
    }
}
