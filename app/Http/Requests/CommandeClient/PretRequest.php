<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeClient;

use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Annonce au pass, et sa dévalidation — les deux gestes partagent cette requête :
 * même cible (des lignes d'une note), même droit, l'un défaisant l'autre.
 *
 * `lignes` vise un ticket précis ; absent, toute la production concernée de la
 * note est prise. Même convention que {@see ServirRequest}.
 *
 * Gouverné par `commande_client.marquer_pret` et NON par `update` : c'est tout
 * l'intérêt de séparer l'annonce du service. La cuisine annonce sans pouvoir
 * modifier ni servir les notes de salle.
 */
class PretRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CommandeClient $commande */
        $commande = $this->route('commande');

        return $this->user()->can('marquerPret', $commande);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lignes' => ['sometimes', 'array'],
            'lignes.*' => ['integer', 'exists:lignes_commande_client,id'],
        ];
    }
}
