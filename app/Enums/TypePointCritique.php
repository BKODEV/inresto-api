<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nature d'un point critique de production.
 *
 * Liste fermée : ces points servent à contrôler, et un contrôle qui ne sait pas
 * distinguer un allergène d'une température de cuisson ne contrôle rien. Les
 * catégories suivent la logique HACCP sans en prétendre l'exhaustivité.
 */
enum TypePointCritique: string
{
    /** Température ou temps à cœur — le plat est-il cuit. */
    case Cuisson = 'cuisson';

    /** Descente en température après cuisson. */
    case Refroidissement = 'refroidissement';

    /** Chaîne du froid, durée de vie, contenant. */
    case Conservation = 'conservation';

    /** Ce qui rend le plat dangereux pour certains clients. */
    case Allergene = 'allergene';

    /** Geste ou matériel dont dépend la salubrité. */
    case Hygiene = 'hygiene';

    case Autre = 'autre';

    public function libelle(): string
    {
        return match ($this) {
            self::Cuisson => 'Cuisson',
            self::Refroidissement => 'Refroidissement',
            self::Conservation => 'Conservation',
            self::Allergene => 'Allergène',
            self::Hygiene => 'Hygiène',
            self::Autre => 'Autre',
        };
    }
}
