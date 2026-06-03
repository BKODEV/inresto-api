<?php

declare(strict_types=1);

namespace App\Http\Requests\TableSalle;

use App\Enums\StatutTable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangerStatutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('table'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'statut' => ['required', Rule::enum(StatutTable::class)],
        ];
    }
}
