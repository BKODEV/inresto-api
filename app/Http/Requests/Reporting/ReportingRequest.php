<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation commune à tous les endpoints reporting.
 *
 * - Filtre temporel uniforme (periode/date/date_debut/date_fin). La plage exige
 *   date_debut ≤ date_fin (sinon 422).
 * - Filtres de périmètre optionnels (site_id, point_de_vente_id).
 * - Filtres de présentation optionnels (tri, ordre, limit, detail) ; ignorés par
 *   les rapports qui ne les exploitent pas.
 *
 * L'autorisation par permission (reporting.*) est portée par les controllers,
 * pas ici.
 */
class ReportingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periode' => ['sometimes', 'in:jour,semaine,mois,plage'],
            'date' => ['sometimes', 'date'],
            'date_debut' => ['required_if:periode,plage', 'date'],
            'date_fin' => ['required_if:periode,plage', 'date', 'after_or_equal:date_debut'],
            'site_id' => ['sometimes', 'integer', 'exists:sites,id'],
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'tri' => ['sometimes', 'in:ca,quantite'],
            'ordre' => ['sometimes', 'in:asc,desc'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'detail' => ['sometimes', 'boolean'],
            // Lecture comparée : le rapport rend aussi la période immédiatement
            // précédente et la variation. Piloter, c'est comparer — jusqu'ici on
            // ne pouvait que constater.
            'comparer' => ['sometimes', 'boolean'],
        ];
    }
}
