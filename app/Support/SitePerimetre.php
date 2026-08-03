<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Source unique du périmètre de sites d'un utilisateur (pivot `user_site`).
 *
 * Le multi-site est structurant : stock, PMP et budgets sont propres à chaque
 * site, et il n'existe aucun transfert inter-sites. Une écriture posée sur le
 * mauvais site ne se rattrape donc que par une correction d'inventaire des deux
 * côtés — d'où le bornage systématique des lectures comme des écritures.
 *
 * Deux niveaux de « global » cohabitent volontairement :
 *
 * - **Opérationnel** (stock, inventaire, réception, budget) : seul le
 *   super-admin dépasse son périmètre, comme dans les `before()` des policies.
 * - **Analytique** (reporting) : la permission `reporting.global` élargit la
 *   lecture à tous les sites. Consulter les chiffres d'un site ne vaut pas
 *   autorisation d'y écrire, d'où le passage explicite de `$elargi`.
 */
final class SitePerimetre
{
    /**
     * Sites sur lesquels l'utilisateur peut opérer.
     *
     * @return int[]|null `null` = aucun bornage (tous les sites).
     */
    public static function autorises(User $user, bool $elargi = false): ?array
    {
        if ($user->isSuperAdmin() || $elargi) {
            return null;
        }

        /** @var int[] $sites */
        $sites = $user->sites()->pluck('sites.id')->all();

        return $sites;
    }

    /**
     * Périmètre effectif d'une requête, en tenant compte d'un `site_id` demandé.
     *
     * @param  int|null  $siteId  Site explicitement demandé, ou `null`.
     * @return int[]|null `null` = aucun bornage.
     *
     * @throws AccessDeniedHttpException Si le site demandé est hors périmètre.
     */
    public static function resoudre(User $user, ?int $siteId, bool $elargi = false): ?array
    {
        $autorises = self::autorises($user, $elargi);

        if ($siteId === null) {
            return $autorises;
        }

        if ($autorises !== null && ! in_array($siteId, $autorises, true)) {
            throw new AccessDeniedHttpException('Site hors de votre périmètre.');
        }

        return [$siteId];
    }
}
