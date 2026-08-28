<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommandeClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'table_id' => $this->table_id,
            // Numéro figé à la commande : un renommage de table ne réécrit
            // pas les documents déjà émis.
            'table_numero' => $this->table_numero,
            'session_caisse_id' => $this->session_caisse_id,
            'user_id' => $this->user_id,
            // Nom du serveur, pour le poste de caisse. Composé ici plutôt que
            // d'exposer la fiche utilisateur : la caisse n'a besoin que de
            // l'identifier, pas de ses coordonnées.
            'serveur' => $this->whenLoaded('user', fn (): string => trim($this->user->prenom.' '.$this->user->nom)),
            'reservation_id' => $this->reservation_id,
            'appartement_id' => $this->appartement_id,
            'appartement_nom' => $this->appartement_nom,
            'appartement_a_verifier' => $this->appartement_a_verifier,
            'date_heure' => $this->date_heure?->toIso8601String(),
            'statut' => $this->statut->value,
            // Depuis quand la table attend son addition. Le serveur n'a que ce
            // repère pour distinguer un client au comptoir d'un client parti.
            'servie_le' => $this->servie_le?->toIso8601String(),
            // Piste d'audit de la clôture ; null tant que non encaissée.
            'encaisse_par' => $this->encaisse_par,
            'encaisse_le' => $this->encaisse_le?->toIso8601String(),
            'montant_total' => $this->montant_total,
            // Valeurs calculées à la volée (non stockées).
            'montant_du' => number_format($this->montantDu(), 2, '.', ''),
            'montant_paye' => number_format($this->montantPaye(), 2, '.', ''),
            'reste_a_payer' => number_format($this->resteAPayer(), 2, '.', ''),
            // Vrai tant que rien n'a jamais garni la commande : le front propose
            // alors « Libérer la table » plutôt que l'annulation motivée.
            'peut_etre_liberee' => $this->peutEtreLiberee(),
            'notes' => $this->notes,
            'reference_facture_normalisee' => $this->reference_facture_normalisee,
            'table' => new TableSalleResource($this->whenLoaded('table')),
            'session_caisse' => new SessionCaisseResource($this->whenLoaded('sessionCaisse')),
            'lignes' => LigneCommandeClientResource::collection($this->whenLoaded('lignes')),
            'paiements' => PaiementResource::collection($this->whenLoaded('paiements')),
            'remises' => RemiseResource::collection($this->whenLoaded('remises')),
        ];
    }
}
