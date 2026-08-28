<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeClient;

use App\Models\CommandeClient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Envoi d'un tour en cuisine, panier compris.
 *
 * `lignes` est optionnel : la prise de commande envoie tout son panier en un
 * seul appel, mais l'ajout ligne à ligne suivi d'un envoi reste valable.
 *
 * Pas de `distinct` sur `produit_id` : commander deux fois le même plat dans un
 * même tour est légitime — deux clients, deux cuissons différentes en
 * commentaire. C'est la différence avec un catalogue, où le doublon est une
 * faute de saisie.
 */
class EnvoyerEnCuisineRequest extends FormRequest
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
            'lignes' => ['sometimes', 'array', 'max:100'],
            'lignes.*.produit_id' => ['required', 'integer', 'exists:produits,id'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'lignes.*.commentaire' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lignes.max' => 'Un envoi est limité à 100 lignes. Envoyez en plusieurs tours.',
        ];
    }
}
