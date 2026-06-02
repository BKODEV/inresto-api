<?php

declare(strict_types=1);

namespace App\Http\Requests\FicheTechnique;

/**
 * La mise à jour suit exactement la logique de versioning de la création
 * (mêmes règles) ; seule l'autorisation diffère.
 */
class UpdateFicheTechniqueRequest extends StoreFicheTechniqueRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('fiche_technique'));
    }
}
