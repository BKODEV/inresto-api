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
                // Une matière ne peut figurer qu'une fois par commande : sinon le
                // cumul commandé/reçu du module réception devient ambigu.
                'distinct',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
                new MatiereAuCatalogue($fournisseurId),
            ],
            // Pas d'unité au contrat : l'unité d'une ligne est TOUJOURS l'unité
            // d'achat du catalogue (couple fournisseur/matière), estampée serveur.
            'lignes.*.quantite_commandee' => ['required_with:lignes', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire_previsionnel' => ['required_with:lignes', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.*.matiere_premiere_id.distinct' => 'Cet article est déjà sélectionné dans la commande : modifiez la quantité de la ligne existante.',
        ];
    }
}
