<?php

declare(strict_types=1);

namespace App\Http\Requests\Journal;

use App\Services\JournalService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres du journal d'activité.
 *
 * La plage de dates n'est pas optionnelle par accident : le journal assemble
 * ses sources en PHP, il doit donc toujours lire une fenêtre bornée. Absente,
 * elle est ramenée à la journée courante par le contrôleur.
 */
class IndexJournalRequest extends FormRequest
{
    /** Types d'événements assemblés par {@see JournalService}. */
    public const TYPES = ['annulation', 'remise', 'paiement', 'encaissement', 'impression'];

    public function authorize(): bool
    {
        return $this->user()->can('journal.view');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'date', 'after_or_equal:date_debut'],
            'type' => ['sometimes', 'array'],
            'type.*' => [Rule::in(self::TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'type.*.in' => "Type d'événement inconnu.",
        ];
    }
}
