<?php

declare(strict_types=1);

namespace App\Http\Requests\Paiement;

use App\Enums\Statut;
use App\Models\Paiement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePaiementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Paiement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode_de_paiement_id' => [
                'required',
                'integer',
                Rule::exists('modes_de_paiement', 'id')->where('statut', Statut::Actif->value),
            ],
            'montant' => ['required', 'numeric', 'gt:0'],
            // Ce que le client laisse au serveur. Reste en tiroir sans être de
            // la recette : c'est `commande.user_id` qui en est le bénéficiaire.
            'pourboire' => ['nullable', 'numeric', 'min:0'],
            // Espèces tendues. Un `gte:montant` ne suffit plus : le billet doit
            // couvrir la vente ET le pourboire, sinon on rendrait une monnaie
            // qu'on n'a pas reçue. Contrôlé dans withValidator, faute de règle
            // native comparant à une somme de deux champs.
            'montant_recu' => ['nullable', 'numeric', 'gte:montant'],
            'reference_externe' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $recu = $this->input('montant_recu');
            if ($recu === null) {
                return;
            }

            $du = (float) $this->input('montant') + (float) $this->input('pourboire', 0);

            if ((float) $recu + 0.001 < $du) {
                $validator->errors()->add(
                    'montant_recu',
                    'Le montant reçu doit couvrir le montant encaissé et le pourboire.'
                );
            }
        });
    }
}
