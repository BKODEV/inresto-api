<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Place un élément ordonnable (ligne de carte, catégorie…) dans son périmètre.
 *
 * Sémantique d'INSERTION (validée 2026-06-04) : poser un élément à la position
 * N décale d'un cran tous les voisins dont l'ordre est >= N — jamais deux
 * éléments au même rang. Un ordre cible absent ou <= 0 place l'élément en fin
 * de liste (max + 1, donc le premier élément démarre à 1, pas à 0).
 */
class OrdreService
{
    /**
     * @param  Closure(): Builder  $perimetre  Renvoie une requête FRAÎCHE
     *                                         restreinte au périmètre de tri (carte+section, ou global) et
     *                                         EXCLUANT l'élément en cours de placement.
     */
    public function placer(Closure $perimetre, ?int $ordreCible): int
    {
        if ($ordreCible !== null && $ordreCible > 0) {
            $perimetre()->where('ordre', '>=', $ordreCible)->increment('ordre');

            return $ordreCible;
        }

        return (int) $perimetre()->max('ordre') + 1;
    }
}
