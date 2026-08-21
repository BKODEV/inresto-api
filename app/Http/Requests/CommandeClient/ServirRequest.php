<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeClient;

use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marque servi un tour, ou toute la production en attente.
 *
 * `lignes` cible un ticket précis : la cuisine valide ce qu'elle vient de
 * produire, et une note peut porter deux tours en production à la fois. Absent,
 * tout ce qui est parti et non servi est servi — c'est le geste de la caisse.
 */
class ServirRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CommandeClient $commande */
        $commande = $this->route('commande');

        return $this->user()->can('update', $commande);
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
