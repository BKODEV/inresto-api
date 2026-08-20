<?php

declare(strict_types=1);

namespace App\Http\Requests\TableSalle;

use App\Models\TableSalle;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableSalleLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TableSalle::class);
    }

    /**
     * Création en lot : mêmes règles que l'ajout unitaire, appliquées ligne à
     * ligne. Saisir un plan de salle de trente tables une par une est
     * impraticable, en particulier à l'ouverture d'un site.
     *
     * `distinct` s'ajoute à l'unicité en base : sans lui, deux fois le même
     * numéro dans une seule requête passerait la règle `unique` (qui interroge
     * l'état d'avant la requête) et violerait l'index à l'insertion.
     * En `ignore_case`, parce que « t1 » et « T1 » désignent la même table pour
     * un serveur en salle.
     *
     * Pas de champ `statut` : une table naît libre, et le lot sert à créer une
     * salle vide, pas à décrire un service en cours.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'lignes' => ['required', 'array', 'min:1', 'max:100'],
            'lignes.*.numero' => [
                'required',
                'string',
                'max:50',
                'distinct:ignore_case',
                Rule::unique('tables_salle', 'numero')
                    ->where('point_de_vente_id', $this->integer('point_de_vente_id')),
            ],
            'lignes.*.capacite' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.required' => 'Ajoutez au moins une table.',
            'lignes.max' => 'Un lot est limité à 100 tables. Créez-les en plusieurs séries.',
            'lignes.*.numero.required' => 'Le numéro de la table n° :position est requis.',
            'lignes.*.numero.distinct' => 'Le numéro de la table n° :position apparaît deux fois dans ce lot.',
            // Une table archivée conserve son numéro : c'est voulu, on réactive
            // plutôt que de recréer, pour ne pas dédoubler l'historique.
            'lignes.*.numero.unique' => 'Le numéro de la table n° :position existe déjà sur ce point de vente (y compris sur une table archivée, à réactiver plutôt qu\'à recréer).',
            'lignes.*.capacite.required' => 'La capacité de la table n° :position est requise.',
            'lignes.*.capacite.min' => 'La capacité de la table n° :position doit valoir au moins 1.',
        ];
    }
}
