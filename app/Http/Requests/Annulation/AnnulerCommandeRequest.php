<?php

declare(strict_types=1);

namespace App\Http\Requests\Annulation;

use App\Models\Annulation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AnnulerCommandeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Annulation::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string'],
        ];
    }
}
