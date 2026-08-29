<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Annulation;
use App\Models\CommandeClient;
use App\Models\Impression;
use App\Models\Paiement;
use App\Models\Remise;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Models\Versement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Journal d'activité : frise chronologique des gestes sensibles.
 *
 * N'écrit RIEN. Les traces existent déjà, chacune dans la table du module qui
 * la produit, avec son contexte métier propre — `annulations` porte un motif et
 * le sort du stock, `remises` un motif et une valeur, `paiements` un mode.
 * Recopier tout cela dans une table d'événements générique perdrait ce
 * contexte et créerait deux vérités à maintenir. Le journal les relit.
 *
 * Contrepartie assumée : l'assemblage se fait en PHP et non en SQL, donc la
 * fenêtre de lecture doit rester bornée dans le temps. Le contrôleur impose
 * une plage de dates (par défaut la journée courante) et {@link LIMITE_SOURCE}
 * plafonne chaque source.
 */
class JournalService
{
    /**
     * Plafond par source. Une journée chargée produit quelques centaines
     * d'événements toutes sources confondues ; ce plafond n'existe que pour
     * qu'une plage de dates très large ne fasse pas tomber le serveur.
     */
    private const LIMITE_SOURCE = 2000;

    /**
     * @param  int[]|null  $sites  Périmètre de sites, ou null pour aucun filtre.
     * @param  string[]  $types  Types d'événements retenus ; vide = tous.
     * @return Collection<int, array<string, mixed>>
     */
    public function evenements(
        Carbon $debut,
        Carbon $fin,
        ?array $sites,
        array $types = [],
        ?int $pointDeVenteId = null,
    ): Collection {
        $retenu = fn (string $type): bool => $types === [] || in_array($type, $types, true);

        $evenements = collect();

        if ($retenu('annulation')) {
            $evenements = $evenements->concat($this->annulations($debut, $fin));
        }
        if ($retenu('remise')) {
            $evenements = $evenements->concat($this->remises($debut, $fin, $sites, $pointDeVenteId));
        }
        if ($retenu('paiement')) {
            $evenements = $evenements->concat($this->paiements($debut, $fin, $sites, $pointDeVenteId));
        }
        if ($retenu('encaissement')) {
            $evenements = $evenements->concat($this->encaissements($debut, $fin, $sites, $pointDeVenteId));
        }
        if ($retenu('versement')) {
            $evenements = $evenements->concat($this->versements($debut, $fin, $sites, $pointDeVenteId));
        }
        if ($retenu('impression')) {
            $evenements = $evenements->concat($this->impressions($debut, $fin, $sites, $pointDeVenteId));
        }

        return $evenements->sortByDesc('date_heure')->values();
    }

    /** Nom affichable de l'auteur, ou null si le compte a été supprimé. */
    private static function auteur(?User $user): ?string
    {
        return $user === null ? null : trim($user->prenom.' '.$user->nom);
    }

    /**
     * Le périmètre de site passe par la table : commande → table → point de
     * vente → site. C'est le seul chemin, il n'existe pas de `site_id` direct
     * sur une commande.
     *
     * @param  Builder<CommandeClient>  $query
     * @param  int[]|null  $sites
     */
    private function bornerAuPerimetre(Builder $query, ?array $sites, ?int $pointDeVenteId): void
    {
        if ($sites !== null) {
            $query->whereHas(
                'table.pointDeVente',
                fn (Builder $pdv) => $pdv->whereIn('site_id', $sites)
            );
        }

        if ($pointDeVenteId !== null) {
            $query->whereHas(
                'table',
                fn (Builder $table) => $table->where('point_de_vente_id', $pointDeVenteId)
            );
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function annulations(Carbon $debut, Carbon $fin): Collection
    {
        return Annulation::query()
            ->with('user')
            ->whereBetween('date_heure', [$debut, $fin])
            ->orderByDesc('date_heure')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (Annulation $a): array => [
                'type' => 'annulation',
                'date_heure' => $a->date_heure,
                'auteur' => self::auteur($a->user),
                'libelle' => 'Annulation — '.class_basename($a->annulable_type),
                'reference' => (string) $a->annulable_id,
                'montant' => null,
                'motif' => $a->motif,
                // `sort` est une colonne texte, pas un enum : `?->value`
                // renvoyait null sur une chaîne (avec un warning PHP), et le
                // devenir du stock — remise en stock ou perte — ne s'affichait
                // jamais, alors que c'est le contexte que le journal promet.
                'detail' => $a->sort,
            ]);
    }

    /**
     * @param  int[]|null  $sites
     * @return Collection<int, array<string, mixed>>
     */
    private function remises(Carbon $debut, Carbon $fin, ?array $sites, ?int $pointDeVenteId): Collection
    {
        return Remise::query()
            ->with('user')
            ->whereBetween('date_heure', [$debut, $fin])
            ->whereHas('commande', function (Builder $commande) use ($sites, $pointDeVenteId): void {
                $this->bornerAuPerimetre($commande, $sites, $pointDeVenteId);
            })
            ->orderByDesc('date_heure')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (Remise $r): array => [
                'type' => 'remise',
                'date_heure' => $r->date_heure,
                'auteur' => self::auteur($r->user),
                'libelle' => 'Remise appliquée',
                'reference' => 'Commande #'.$r->commande_client_id,
                'montant' => (float) $r->valeur,
                'motif' => $r->motif,
                'detail' => $r->type->value,
            ]);
    }

    /**
     * @param  int[]|null  $sites
     * @return Collection<int, array<string, mixed>>
     */
    private function paiements(Carbon $debut, Carbon $fin, ?array $sites, ?int $pointDeVenteId): Collection
    {
        return Paiement::query()
            ->with(['user', 'modePaiement'])
            ->whereBetween('date_heure', [$debut, $fin])
            ->whereHas('commande', function (Builder $commande) use ($sites, $pointDeVenteId): void {
                $this->bornerAuPerimetre($commande, $sites, $pointDeVenteId);
            })
            ->orderByDesc('date_heure')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (Paiement $p): array => [
                'type' => 'paiement',
                'date_heure' => $p->date_heure,
                'auteur' => self::auteur($p->user),
                'libelle' => 'Paiement encaissé',
                'reference' => 'Commande #'.$p->commande_client_id,
                'montant' => (float) $p->montant,
                'motif' => null,
                'detail' => $p->modePaiement?->libelle,
            ]);
    }

    /**
     * Clôture de note. Distincte des paiements : une note soldée sans aucun
     * paiement (offerte, couverte par un avoir) n'apparaîtrait nulle part
     * ailleurs, et c'est précisément le cas qu'un contrôle cherche.
     *
     * @param  int[]|null  $sites
     * @return Collection<int, array<string, mixed>>
     */
    private function encaissements(Carbon $debut, Carbon $fin, ?array $sites, ?int $pointDeVenteId): Collection
    {
        $query = CommandeClient::query()
            ->with('encaissePar')
            ->whereNotNull('encaisse_le')
            ->whereBetween('encaisse_le', [$debut, $fin]);

        $this->bornerAuPerimetre($query, $sites, $pointDeVenteId);

        return $query
            ->orderByDesc('encaisse_le')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (CommandeClient $c): array => [
                'type' => 'encaissement',
                'date_heure' => $c->encaisse_le,
                'auteur' => self::auteur($c->encaissePar),
                'libelle' => 'Note clôturée',
                'reference' => 'Commande #'.$c->id,
                'montant' => (float) $c->montant_total,
                'motif' => null,
                'detail' => 'Table '.($c->table_numero ?? '—'),
            ]);
    }

    /**
     * Sorties d'argent du tiroir. Le geste sensible par excellence : de
     * l'argent quitte la caisse sans qu'aucune vente ne s'y oppose.
     *
     * Le périmètre passe par la caisse et non par une table, une session n'ayant
     * pas de commande à remonter.
     *
     * @param  int[]|null  $sites
     * @return Collection<int, array<string, mixed>>
     */
    private function versements(Carbon $debut, Carbon $fin, ?array $sites, ?int $pointDeVenteId): Collection
    {
        return Versement::query()
            ->with('user')
            ->whereBetween('date_heure', [$debut, $fin])
            ->whereHas('caisse.pointDeVente', function (Builder $pdv) use ($sites, $pointDeVenteId): void {
                $pdv->when($sites !== null, fn (Builder $q) => $q->whereIn('site_id', $sites))
                    ->when($pointDeVenteId !== null, fn (Builder $q) => $q->where('id', $pointDeVenteId));
            })
            ->orderByDesc('date_heure')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (Versement $v): array => [
                'type' => 'versement',
                'date_heure' => $v->date_heure,
                'auteur' => self::auteur($v->user),
                'libelle' => 'Versement — '.$v->destination->libelle(),
                'reference' => 'Session #'.$v->session_caisse_id,
                'montant' => (float) $v->montant,
                'motif' => $v->notes,
                'detail' => $v->remis_a,
            ]);
    }

    /**
     * @param  int[]|null  $sites
     * @return Collection<int, array<string, mixed>>
     */
    private function impressions(Carbon $debut, Carbon $fin, ?array $sites, ?int $pointDeVenteId): Collection
    {
        $commandeMorph = (new CommandeClient)->getMorphClass();
        $sessionMorph = (new SessionCaisse)->getMorphClass();

        $commandesDuPerimetre = CommandeClient::query()
            ->when(true, function (Builder $commande) use ($sites, $pointDeVenteId): void {
                $this->bornerAuPerimetre($commande, $sites, $pointDeVenteId);
            })
            ->select('id');

        // Une session ne passe pas par `table.pointDeVente` comme une commande :
        // son périmètre se remonte par sa caisse. Sans cette branche, le rapport
        // de fermeture serait le seul document d'argent sans trace au journal.
        $sessionsDuPerimetre = SessionCaisse::query()
            ->whereHas('caisse.pointDeVente', function (Builder $pdv) use ($sites, $pointDeVenteId): void {
                $pdv->when($sites !== null, fn (Builder $q) => $q->whereIn('site_id', $sites))
                    ->when($pointDeVenteId !== null, fn (Builder $q) => $q->where('id', $pointDeVenteId));
            })
            ->select('id');

        return Impression::query()
            ->with('user')
            ->whereBetween('date_heure', [$debut, $fin])
            ->where(function (Builder $requete) use ($commandeMorph, $sessionMorph, $commandesDuPerimetre, $sessionsDuPerimetre): void {
                $requete
                    ->where(fn (Builder $q) => $q
                        ->where('imprimable_type', $commandeMorph)
                        ->whereIn('imprimable_id', $commandesDuPerimetre))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('imprimable_type', $sessionMorph)
                        ->whereIn('imprimable_id', $sessionsDuPerimetre));
            })
            ->orderByDesc('date_heure')
            ->limit(self::LIMITE_SOURCE)
            ->get()
            ->map(fn (Impression $i): array => [
                'type' => 'impression',
                'date_heure' => $i->date_heure,
                'auteur' => self::auteur($i->user),
                'libelle' => $i->type->libelle().' imprimé(e)',
                'reference' => $i->imprimable_type === $sessionMorph
                    ? 'Session #'.$i->imprimable_id
                    : 'Commande #'.$i->imprimable_id,
                'montant' => null,
                'motif' => null,
                'detail' => null,
            ]);
    }
}
