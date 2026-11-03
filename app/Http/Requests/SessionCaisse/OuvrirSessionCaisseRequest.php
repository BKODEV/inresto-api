<?php

declare(strict_types=1);

namespace App\Http\Requests\SessionCaisse;

use App\Models\Caisse;
use App\Models\SessionCaisse;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OuvrirSessionCaisseRequest extends FormRequest
{
    /**
     * La caisse visée est passée à la policy : c'est elle qui porte le site, et
     * ouvrir un tiroir hors de son périmètre n'a pas plus de sens que d'y
     * encaisser.
     *
     * `authorize()` s'exécute AVANT la validation : la caisse peut donc être
     * absente ou inexistante à ce stade. On passe alors `null`, et c'est
     * `rules()` qui rendra le 422 attendu — refuser en 403 une requête dont le
     * `caisse_id` est simplement manquant induirait en erreur.
     */
    public function authorize(): bool
    {
        $caisse = Caisse::query()->find($this->input('caisse_id'));

        return $this->user()->can('create', [SessionCaisse::class, $caisse]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'caisse_id' => ['required', 'integer', 'exists:caisses,id'],
            'fonds_initial' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
