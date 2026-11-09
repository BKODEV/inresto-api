<?php

declare(strict_types=1);

namespace App\Http\Requests\BonCuisine;

use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bons d'un point de vente : la file de la caisse et l'impression en lot.
 *
 * - `a_imprimer` : seulement ce qui attend encore d'être sorti, tous jours
 *   confondus — un bon oublié la veille doit rester visible ;
 * - `jour` : sinon, les bons d'une journée (aujourd'hui par défaut) ;
 * - `bons[]` : à l'impression, des bons précis plutôt que toute la file.
 */
class FiltreBonsCuisineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CommandeClient::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'a_imprimer' => ['sometimes', 'boolean'],
            'jour' => ['sometimes', 'date_format:Y-m-d'],
            'bons' => ['sometimes', 'array', 'min:1', 'max:100'],
            'bons.*' => ['integer', 'distinct'],
        ];
    }
}
