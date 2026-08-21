<?php

declare(strict_types=1);

namespace App\Http\Requests\Annulation;

use App\Models\Annulation;
use App\Models\LigneCommandeClient;
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
        /** @var LigneCommandeClient $ligne */
        $ligne = $this->route('ligne');

        return [
            // Motif exigé seulement si la ligne est partie en cuisine : elle a
            // alors une conséquence — production lancée, éventuellement stock
            // déchargé. Une ligne encore au brouillon se retire sans rien
            // justifier, sinon la table `annulations` — celle qui sert à
            // repérer les fraudes — se remplirait de « erreur de saisie ».
            'motif' => [$ligne->estEnvoyee() ? 'required' : 'nullable', 'string'],
            // `sort` n'est obligatoire que si la LIGNE a déjà été déstockée.
            'sort' => [
                $ligne->estServie() ? 'required' : 'nullable',
                Rule::in(['remise_en_stock', 'perte']),
            ],
        ];
    }
}
