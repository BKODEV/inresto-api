<?php

declare(strict_types=1);

namespace App\Http\Requests\Carte;

use App\Enums\CarteStatut;
use App\Models\Carte;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('carte'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'libelle' => ['sometimes', 'string', 'max:255'],
            'statut'  => ['sometimes', Rule::enum(CarteStatut::class)],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator) {
            /** @var Carte $carte */
            $carte = $this->route('carte');
            $statut = $this->input('statut');

            if ($statut === CarteStatut::Active->value) {
                $existe = Carte::where('point_de_vente_id', $carte->point_de_vente_id)
                    ->where('statut', CarteStatut::Active->value)
                    ->where('id', '!=', $carte->id)
                    ->exists();

                if ($existe) {
                    $validator->errors()->add('statut', 'Ce point de vente a déjà une carte active.');
                }
            }
        });
    }
}
