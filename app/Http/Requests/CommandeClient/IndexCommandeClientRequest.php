<?php

declare(strict_types=1);

namespace App\Http\Requests\CommandeClient;

use App\Enums\StatutCommandeClient;
use App\Models\CommandeClient;
use App\Models\Paiement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtres de l'index des commandes client.
 *
 * Validés plutôt que simplement lus : un statut inconnu doit répondre 422, pas
 * renvoyer une liste vide qui ferait croire à une salle déserte.
 *
 * `statut` est un tableau : l'écran de prise de commande a besoin des trois
 * statuts vivants (ouverte, envoyée en cuisine, servie) en une seule requête.
 */
class IndexCommandeClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', CommandeClient::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'point_de_vente_id' => ['sometimes', 'integer', 'exists:points_de_vente,id'],
            'table_id' => ['sometimes', 'integer', 'exists:tables_salle,id'],
            'session_caisse_id' => ['sometimes', 'integer', 'exists:sessions_caisse,id'],
            'statut' => ['sometimes', 'array', 'min:1'],
            'statut.*' => [Rule::enum(StatutCommandeClient::class)],
            // `date_heure` est un timestamp : les bornes sont élargies à la
            // journée entière côté contrôleur, sinon la borne haute exclurait
            // tout ce qui suit minuit du dernier jour.
            'date_debut' => ['sometimes', 'date'],
            'date_fin' => ['sometimes', 'date', 'after_or_equal:date_debut'],
            // Charge les paiements et leur mode. Hors de la liste par défaut :
            // l'écran de prise de commande se rafraîchit toutes les vingt
            // secondes et n'a que faire de l'argent. Seule la liste des ventes
            // le demande — et seulement pour qui a le droit de voir l'argent,
            // sinon un serveur lirait le détail du tiroir en ajoutant un
            // paramètre à l'URL.
            'avec_paiements' => [
                'sometimes',
                'boolean',
                Rule::prohibitedIf(fn (): bool => $this->user()->cannot('viewAny', Paiement::class)),
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_fin.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
        ];
    }
}
