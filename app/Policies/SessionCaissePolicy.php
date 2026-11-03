<?php

declare(strict_types=1);

namespace App\Policies;

use App\Http\Controllers\Api\SessionCaisseController;
use App\Http\Requests\SessionCaisse\OuvrirSessionCaisseRequest;
use App\Models\Caisse;
use App\Models\SessionCaisse;
use App\Models\User;
use App\Services\CommandeClientService;

/**
 * Une session de caisse est une OPÉRATION : elle appartient au site de son
 * tiroir, et non au fonds commun des définitions partagées.
 *
 * Le périmètre y était absent alors que la chaîne salle, elle, le vérifiait
 * depuis le Lot B ({@see CommandeClientService::assertPeutUtiliserSession()}).
 * Le serveur qui utilisait une session était donc borné pendant que le caissier
 * qui l'ouvre, la ferme et la vide ne l'était pas — un montant théorique, un
 * écart et un motif de forçage se lisaient d'un site à l'autre.
 */
class SessionCaissePolicy
{
    public function before(User $user, string $_ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('session_caisse.view');
    }

    public function view(User $user, SessionCaisse $session): bool
    {
        return $user->can('session_caisse.view') && $user->appartientAuSite($session->siteId());
    }

    /**
     * Ouvrir une session de caisse.
     *
     * La caisse est facultative pour rester compatible avec un `can()` sans
     * modèle, mais le contrôle n'a de sens qu'avec elle : c'est elle qui porte
     * le site. {@see OuvrirSessionCaisseRequest}
     * la fournit.
     */
    public function create(User $user, ?Caisse $caisse = null): bool
    {
        if (! $user->can('session_caisse.create')) {
            return false;
        }

        return $caisse === null || $user->appartientAuSite($caisse->siteId());
    }

    /**
     * Fermer une session de caisse.
     */
    public function update(User $user, SessionCaisse $session): bool
    {
        return $user->can('session_caisse.update') && $user->appartientAuSite($session->siteId());
    }

    /**
     * Lire, en cours de service, ce que le tiroir DEVRAIT contenir.
     *
     * La règle « on ne souffle jamais le montant attendu » protège l'ACTE DE
     * COMPTAGE, pas l'information : elle vise un acteur (celui qui compte), un
     * écran (la fermeture) et un instant (avant la saisie). Elle n'a jamais
     * interdit à un gérant de savoir, hors de cet acte, où en est son argent.
     *
     * Cette policy ne porte que la permission et le périmètre. Le second
     * garde-fou — le refus au PORTEUR de la session ouverte — vit dans
     * {@see SessionCaisseController::position()} et
     * non ici, précisément parce que `before()` laisse passer le super-admin :
     * ce n'est pas une question de droits mais d'intégrité du comptage, et
     * celui qui comptera ce soir doit compter à l'aveugle quel que soit son rôle.
     */
    public function consulterTheorique(User $user, SessionCaisse $session): bool
    {
        return $user->can('session_caisse.consulter_theorique')
            && $user->appartientAuSite($session->siteId());
    }

    /**
     * Fermer sans que tout soit compté.
     *
     * Distincte de `update` à dessein : savoir fermer une caisse ne doit pas
     * suffire à passer outre le contrôle. C'est la porte de sortie d'un
     * responsable, pas un raccourci de fin de service.
     */
    public function forcer(User $user, SessionCaisse $session): bool
    {
        return $user->can('session_caisse.forcer_fermeture') && $user->appartientAuSite($session->siteId());
    }
}
