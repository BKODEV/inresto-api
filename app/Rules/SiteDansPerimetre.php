<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use App\Support\SitePerimetre;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Garantit qu'un `site_id` soumis en écriture appartient au périmètre de
 * l'utilisateur. Sans elle, un `chef-cuisine` pourrait faire entrer du stock ou
 * corriger un inventaire sur un site dont il n'a pas la charge — erreur que
 * l'absence de transfert inter-sites rend coûteuse à réparer.
 */
class SiteDansPerimetre implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Utilisateur absent ou valeur non numérique : les règles `required` /
        // `exists` qui accompagnent le champ signalent déjà le problème.
        if ($this->user === null || ! is_numeric($value)) {
            return;
        }

        $autorises = SitePerimetre::autorises($this->user);

        if ($autorises !== null && ! in_array((int) $value, $autorises, true)) {
            $fail("Ce site n'est pas dans votre périmètre.");
        }
    }
}
