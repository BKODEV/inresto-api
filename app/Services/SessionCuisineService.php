<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Statut;
use App\Enums\StatutSessionCuisine;
use App\Exceptions\SessionCuisineException;
use App\Models\LigneCommandeClient;
use App\Models\SessionCuisine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un service de cuisine.
 *
 * Indépendant de la session de caisse : la brigade ouvre à la mise en place et
 * ferme après le nettoyage, horaires qui n'ont aucune raison de coïncider avec
 * ceux d'un comptoir (cf. la migration).
 *
 * Ce qu'il apporte au reporting : chaque ligne partie en cuisine porte le
 * service qui l'a produite, donc l'équipe. Sans lui, on sait quel SERVEUR a
 * vendu un plat, jamais quelle brigade l'a fait.
 */
class SessionCuisineService
{
    /**
     * Ouvre un service. Refuse (409) si un service est déjà ouvert sur ce point
     * de vente : deux services ouverts rendraient le rattachement des lignes
     * ambigu. Verrouillé, et doublé d'un index unique partiel en base.
     *
     * @param  array{point_de_vente_id: int, libelle?: string|null, notes?: string|null, brigade?: array<int, string>}  $data
     *
     * @throws SessionCuisineException
     */
    public function ouvrir(array $data, string $userId): SessionCuisine
    {
        return DB::transaction(function () use ($data, $userId): SessionCuisine {
            $pointDeVenteId = (int) $data['point_de_vente_id'];

            $dejaOuverte = SessionCuisine::query()
                ->where('point_de_vente_id', $pointDeVenteId)
                ->where('statut', StatutSessionCuisine::Ouverte->value)
                ->lockForUpdate()
                ->exists();

            if ($dejaOuverte) {
                throw SessionCuisineException::dejaOuverte($pointDeVenteId);
            }

            $session = new SessionCuisine([
                'point_de_vente_id' => $pointDeVenteId,
                'libelle' => $data['libelle'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $session->ouvert_par = $userId;
            $session->ouvert_le = now();
            $session->statut = StatutSessionCuisine::Ouverte;
            $session->save();

            // Qui ouvre fait partie de l'équipe : sans lui, un service tenu par
            // le seul chef sortirait avec une brigade vide.
            $session->brigade()->sync(array_unique([...($data['brigade'] ?? []), $userId]));

            return $session->load(['pointDeVente', 'ouvertPar', 'brigade']);
        });
    }

    /**
     * Ferme un service.
     *
     * Refuse tant que des plats sont partis en cuisine sans être annoncés au
     * pass : les laisser rattachés à un service clos ferait disparaître de la
     * file des plats que quelqu'un doit encore finir.
     *
     * @param  array{notes?: string|null, brigade?: array<int, string>}  $data
     *
     * @throws SessionCuisineException
     */
    public function fermer(SessionCuisine $session, array $data, string $userId): SessionCuisine
    {
        return DB::transaction(function () use ($session, $data, $userId): SessionCuisine {
            $session->refresh();

            if (! $session->estOuverte()) {
                throw SessionCuisineException::dejaFermee($session->id);
            }

            $enCours = $this->lignesNonAnnoncees($session)->count();
            if ($enCours > 0) {
                throw SessionCuisineException::lignesEnCours($session->id, $enCours);
            }

            if (array_key_exists('brigade', $data)) {
                $session->brigade()->sync(array_unique([...$data['brigade'], $session->ouvert_par]));
            }

            $session->statut = StatutSessionCuisine::Fermee;
            $session->ferme_par = $userId;
            $session->ferme_le = now();
            if (($data['notes'] ?? null) !== null) {
                $session->notes = $data['notes'];
            }
            $session->save();

            return $session->load(['pointDeVente', 'ouvertPar', 'fermePar', 'brigade']);
        });
    }

    /**
     * Le service ouvert d'un point de vente, ou null.
     *
     * C'est par là qu'une ligne trouve son service à l'envoi en cuisine. Rendre
     * `null` plutôt que lever : la cuisine n'a pas à bloquer la salle. Une
     * commande envoyée sans service ouvert part quand même, simplement sans
     * être imputée à une équipe — signaler vaut mieux qu'immobiliser.
     */
    public function sessionOuvertePour(int $pointDeVenteId): ?SessionCuisine
    {
        return SessionCuisine::query()
            ->where('point_de_vente_id', $pointDeVenteId)
            ->where('statut', StatutSessionCuisine::Ouverte->value)
            ->first();
    }

    /**
     * Ce que la brigade a produit : par produit, quantité et chiffre d'affaires.
     *
     * Adossé aux LIGNES et non aux paiements, comme tout le reporting : un plat
     * produit est un plat produit, que la note soit encaissée ou non. Les lignes
     * annulées (`statut` inactif) en sont exclues — la brigade les a préparées,
     * mais elles n'ont rien vendu.
     *
     * @return array<int, array<string, mixed>>
     */
    public function production(SessionCuisine $session): array
    {
        return DB::table('lignes_commande_client as l')
            ->join('produits as p', 'p.id', '=', 'l.produit_id')
            ->leftJoin('categories_produit as c', 'c.id', '=', 'p.categorie_id')
            ->where('l.session_cuisine_id', $session->id)
            ->where('l.statut', Statut::Actif->value)
            ->groupBy('l.produit_id', 'p.libelle', 'c.libelle')
            ->selectRaw(
                'l.produit_id, p.libelle as produit, c.libelle as categorie, '.
                'SUM(l.quantite) as quantite, '.
                'SUM(l.quantite * l.prix_unitaire) as ca'
            )
            ->orderByDesc('quantite')
            ->get()
            ->map(fn ($row): array => [
                'produit_id' => (int) $row->produit_id,
                'produit' => $row->produit,
                'categorie' => $row->categorie,
                'quantite' => (float) $row->quantite,
                'ca' => round((float) $row->ca, 2),
            ])
            ->all();
    }

    /**
     * Lignes parties en cuisine et jamais annoncées au pass.
     *
     * @return Builder<LigneCommandeClient>
     */
    private function lignesNonAnnoncees(SessionCuisine $session): Builder
    {
        return LigneCommandeClient::query()
            ->where('session_cuisine_id', $session->id)
            ->where('statut', Statut::Actif->value)
            ->whereNotNull('envoyee_le')
            ->whereNull('prete_le');
    }
}
