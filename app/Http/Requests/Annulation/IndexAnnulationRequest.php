<?php

declare(strict_types=1);

namespace App\Http\Requests\Annulation;

use App\Models\Annulation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des annulations.
 *
 * `sort` ne vaut que pour une ligne déjà déstockée — c'est justement la
 * question qu'on vient poser ici : ce qui a été annulé après service est-il
 * reparti en stock, ou passé en perte.
 */
class IndexAnnulationRequest extends FormRequest
{
    /** Ce qu'une annulation peut porter, tel que la carte morphologique le stocke. */
    public const TYPES = ['commandes_client', 'lignes_commande_client'];

    /** Devenir du stock d'une ligne déjà déchargée. */
    public const SORTS = ['remise_en_stock', 'perte'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Annulation::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'annulable_type' => ['sometimes', Rule::in(self::TYPES)],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'date', 'after_or_equal:date_debut'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'annulable_type.in' => "Type d'objet annulable inconnu.",
            'sort.in' => 'Devenir du stock inconnu.',
        ];
    }
}
