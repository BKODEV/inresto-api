<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\CatalogueFournisseur;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Garantit qu'une matière première commandée figure bien au catalogue du
 * fournisseur visé. Le catalogue porte le prix négocié ET le facteur de
 * conversion `qte_par_unite_achat` indispensable à la réception : commander
 * hors catalogue rendrait la réception impossible.
 */
class MatiereAuCatalogue implements ValidationRule
{
    public function __construct(private readonly ?int $fournisseurId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Fournisseur absent/invalide : sa propre règle le signale déjà.
        if ($this->fournisseurId === null || ! is_numeric($value)) {
            return;
        }

        $auCatalogue = CatalogueFournisseur::query()
            ->where('fournisseur_id', $this->fournisseurId)
            ->where('matiere_premiere_id', (int) $value)
            ->exists();

        if (! $auCatalogue) {
            $fail("Cette matière première n'est pas au catalogue de ce fournisseur.");
        }
    }
}
