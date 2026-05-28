<?php

declare(strict_types=1);

namespace App\Http\Requests\Carte;

use App\Enums\CarteStatut;
use App\Models\Carte;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Carte::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['required', 'integer', 'exists:points_de_vente,id'],
            'libelle'           => ['required', 'string', 'max:255'],
            'statut'            => ['sometimes', Rule::enum(CarteStatut::class)],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            $statut = $this->input('statut');
            $pointDeVenteId = $this->input('point_de_vente_id');

            if ($statut === CarteStatut::Active->value && $pointDeVenteId) {
                $existe = Carte::where('point_de_vente_id', $pointDeVenteId)
                    ->where('statut', CarteStatut::Active->value)
                    ->exists();

                if ($existe) {
                    $validator->errors()->add('statut', 'Ce point de vente a déjà une carte active.');
                }
            }
        });
    }
}
