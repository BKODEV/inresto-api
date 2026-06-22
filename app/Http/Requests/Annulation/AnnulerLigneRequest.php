<?php

declare(strict_types=1);

namespace App\Http\Requests\Annulation;

use App\Models\Annulation;
use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnulerLigneRequest extends FormRequest
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
        /** @var CommandeClient $commande */
        $commande = $this->route('commande');

        return [
            'motif' => ['required', 'string'],
            // `sort` n'est obligatoire que si la ligne a déjà été déstockée
            // (commande servie au moment de l'annulation).
            'sort' => [
                $commande->estServie() ? 'required' : 'nullable',
                Rule::in(['remise_en_stock', 'perte']),
            ],
        ];
    }
}
