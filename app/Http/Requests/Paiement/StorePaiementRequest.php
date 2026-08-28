<?php

declare(strict_types=1);

namespace App\Http\Requests\Paiement;

use App\Enums\Statut;
use App\Models\ModeDePaiement;
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
            // Espèces tendues. Obligatoire dès que l'argent passe par le tiroir :
            // sans lui, la monnaie rendue ne se déduit pas et le reçu retombe sur
            // le montant encaissé, affirmant un appoint juste qui n'a pas eu lieu.
            // Hors tiroir (carte, virement), aucun billet n'est tendu : on ne
            // demande pas de saisir une valeur qui n'existe pas.
            // Un `gte:montant` ne suffit pas : le billet doit couvrir la vente ET
            // le pourboire, sinon on rendrait une monnaie qu'on n'a pas reçue.
            // Contrôlé dans withValidator, faute de règle native comparant à une
            // somme de deux champs.
            'montant_recu' => [
                Rule::requiredIf(fn (): bool => $this->modeAlimenteLeTiroir()),
                'nullable',
                'numeric',
                'gte:montant',
            ],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'montant_recu.required' => 'Les espèces tendues par le client sont obligatoires pour ce mode de paiement.',
        ];
    }

    /**
     * Le mode choisi fait-il entrer l'argent dans le tiroir compté ?
     *
     * Un identifiant absent ou inconnu répond « non » : la règle `exists` du
     * mode signale déjà le problème, et exiger en plus un montant reçu
     * empilerait une erreur trompeuse sur la vraie.
     */
    private function modeAlimenteLeTiroir(): bool
    {
        $id = $this->input('mode_de_paiement_id');

        if (! is_numeric($id)) {
            return false;
        }

        return (bool) ModeDePaiement::query()
            ->whereKey((int) $id)
            ->value('compte_en_caisse');
    }
}
