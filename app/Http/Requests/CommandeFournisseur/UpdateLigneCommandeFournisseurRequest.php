<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeFournisseur;

use App\Rules\MatiereAuCatalogue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLigneCommandeFournisseurRequest extends FormRequest
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
                'sometimes',
                'integer',
                Rule::exists('matieres_premieres', 'id')->where('statut', 'actif'),
                new MatiereAuCatalogue($this->route('commande_fournisseur')->fournisseur_id),
            ],
            // Pas d'unité au contrat : imposée par le catalogue (unité d'achat).
            // Changer de matière ré-estampe automatiquement l'unité côté serveur.
            'quantite_commandee' => ['sometimes', 'numeric', 'gt:0'],
            'prix_unitaire_previsionnel' => ['sometimes', 'numeric', 'gt:0'],
        ];
    }
}
