<?php

declare(strict_types=1);

namespace App\Http\Requests\Remise;

use App\Enums\TypeRemise;
use App\Models\Remise;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRemiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Remise::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TypeRemise::class)],
            'valeur' => ['required', 'numeric'],
            'motif' => ['nullable', 'string'],
        ];
    }

    /**
     * Pourcentage : 0 < valeur < 100. Montant fixe : valeur > 0.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $valeur = (float) $this->input('valeur');

            if ($this->input('type') === TypeRemise::Pourcentage->value) {
                if ($valeur <= 0 || $valeur >= 100) {
                    $validator->errors()->add('valeur', 'Un pourcentage de remise doit être strictement compris entre 0 et 100.');
                }
            } elseif ($this->input('type') === TypeRemise::MontantFixe->value && $valeur <= 0) {
                $validator->errors()->add('valeur', 'Un montant fixe de remise doit être strictement positif.');
            }
        });
    }
}
