<?php

declare(strict_types=1);

namespace App\Http\Requests\Remise;

use App\Enums\TypeRemise;
use App\Models\Remise;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de la liste des remises. Validés et non simplement lus : un `type`
 * inconnu ou une date malformée doit répondre 422 plutôt que de renvoyer
 * silencieusement une liste vide.
 */
class IndexRemiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Remise::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'commande_client_id' => ['sometimes', 'integer', 'exists:commandes_client,id'],
            'type' => ['sometimes', Rule::enum(TypeRemise::class)],
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
        ];
    }
}
