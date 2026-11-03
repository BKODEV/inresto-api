<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Caisse;
use App\Models\User;
use App\Models\Versement;
use App\Rules\SiteDansPerimetre;

/**
 * Sortir de l'argent du tiroir est un geste distinct de l'encaissement : il
 * mérite sa propre permission, sans quoi tout caissier pourrait vider la caisse
 * du seul fait qu'il sait y encaisser.
 */
class VersementPolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('versement.view');
    }

    public function view(User $user, Versement $versement): bool
    {
        return $user->can('versement.view') && $user->appartientAuSite($versement->caisse?->siteId());
    }

    /**
     * Sortir de l'argent d'un tiroir précis.
     *
     * Le site vient de la CAISSE, jamais du corps de la requête : c'est la route
     * qui la désigne, et {@see SiteDansPerimetre} — une règle de
     * validation sur un `site_id` soumis — ne s'applique donc pas ici.
     *
     * Ce contrôle devient critique avec le coffre : un versement `coffre` crée
     * une entrée de coffre, et sans lui n'importe quel porteur de
     * `versement.create` gonflerait le solde d'un site où il n'a rien à faire.
     * Le comptage suivant y sortirait un manquant que le gérant de ce site
     * imputerait à quelqu'un — on passerait d'une écriture indue à la
     * fabrication d'une pièce comptable à charge.
     */
    public function create(User $user, ?Caisse $caisse = null): bool
    {
        if (! $user->can('versement.create')) {
            return false;
        }

        return $caisse === null || $user->appartientAuSite($caisse->siteId());
    }
}
