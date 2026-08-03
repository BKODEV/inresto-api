<?php

declare(strict_types=1);

namespace App\Http\Requests\Stock;

use App\Enums\MouvementType;
use App\Models\MouvementStock;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres du journal des mouvements. Validés (et non simplement lus) car un
 * `type` inconnu ou une date malformée doit répondre 422 plutôt que de
 * renvoyer silencieusement une liste vide ou de faire lever Carbon.
 */
class IndexMouvementStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', MouvementStock::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'matiere_premiere_id' => ['sometimes', 'integer', 'exists:matieres_premieres,id'],
            'type' => ['sometimes', Rule::enum(MouvementType::class)],
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
