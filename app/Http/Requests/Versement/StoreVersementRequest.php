<?php

declare(strict_types=1);

namespace App\Http\Requests\Versement;

use App\Enums\DestinationVersement;
use App\Models\Caisse;
use App\Models\User;
use App\Models\Versement;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVersementRequest extends FormRequest
{
    /**
     * La caisse vient de la ROUTE, jamais du corps : `caisses/{caisse}/versements`.
     * C'est elle qui porte le site, et la passer à la policy est ce qui empêche
     * de vider — ou, avec le coffre, d'alimenter — un tiroir hors de son
     * périmètre.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Versement::class, $this->route('caisse')]);
    }

    /**
     * La caisse dont le tiroir est vidé, telle que la route la désigne.
     */
    private function caisse(): ?Caisse
    {
        $caisse = $this->route('caisse');

        return $caisse instanceof Caisse ? $caisse : null;
    }

    /**
     * Le bénéficiaire d'un reversement doit travailler là où l'argent a été
     * encaissé.
     *
     * `exists:users,id` laissait éteindre la dette de pourboires d'un serveur de
     * n'importe quel site : le solde de l'intéressé tombait sans qu'il ait rien
     * reçu, et la trace désignait un versement d'un tiroir qu'il n'a jamais vu.
     */
    private function beneficiaireDuMemeSite(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $siteId = $this->caisse()?->siteId();
            $beneficiaire = User::query()->find($value);

            if ($beneficiaire !== null && $siteId !== null && ! $beneficiaire->appartientAuSite($siteId)) {
                $fail('Ce bénéficiaire ne travaille pas sur le site de cette caisse.');
            }
        };
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0'],
            'destination' => ['required', Rule::enum(DestinationVersement::class)],
            // De quel solde cet argent se retire. Obligatoire pour un
            // reversement de pourboires, interdit ailleurs : posé par erreur sur
            // un dépôt bancaire, il effacerait silencieusement la dette d'un
            // serveur.
            'beneficiaire_id' => [
                Rule::requiredIf(fn (): bool => $this->input('destination') === DestinationVersement::Personnel->value),
                Rule::prohibitedIf(fn (): bool => $this->input('destination') !== DestinationVersement::Personnel->value),
                'uuid',
                Rule::exists('users', 'id'),
                $this->beneficiaireDuMemeSite(),
            ],
            // Qui a EMPORTÉ l'argent : une société de collecte, un convoyeur, un
            // comptable. Du texte libre, car ces gens-là n'ont pas de compte.
            'remis_a' => ['nullable', 'string', 'max:255'],
            // Bordereau de dépôt, pour un versement en banque.
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'beneficiaire_id.required' => 'Indiquez à quel membre du personnel ce pourboire est reversé.',
            'beneficiaire_id.prohibited' => 'Un bénéficiaire ne se désigne que pour un reversement de pourboires.',
        ];
    }
}
