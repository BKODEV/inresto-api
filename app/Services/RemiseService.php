<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeRemise;
use App\Exceptions\RemiseException;
use App\Models\CommandeClient;
use App\Models\Remise;
use App\Models\User;

/**
 * Applique des remises à une commande (ouverte ou servie). Au-delà d'un seuil
 * configurable (config inresto.remise_seuil_manager), une remise nécessite la
 * permission `remise.autoriser_au_dela_seuil`.
 */
class RemiseService
{
    /**
     * @param  array{type: string, valeur: float|string, motif?: string|null}  $data
     *
     * @throws RemiseException
     */
    public function appliquer(CommandeClient $commande, array $data, string $userId): Remise
    {
        if (! $commande->estOuverte() && ! $commande->estServie()) {
            throw RemiseException::statutInvalide($commande->id);
        }

        $type = TypeRemise::from($data['type']);
        $valeur = (float) $data['valeur'];

        $this->assertSeuil($commande, $type, $valeur, $userId);

        return $commande->remises()->create([
            'user_id' => $userId,
            'type' => $type->value,
            'valeur' => $valeur,
            'motif' => $data['motif'] ?? null,
        ])->load('user');
    }

    /**
     * Vérifie le seuil : si le pourcentage effectif de la remise (rapporté au
     * total des lignes actives) dépasse le seuil, la permission spéciale est
     * requise. Total à 0 → seuil non applicable. Le super-admin passe toujours.
     *
     * @throws RemiseException
     */
    private function assertSeuil(CommandeClient $commande, TypeRemise $type, float $valeur, string $userId): void
    {
        $base = $commande->montantLignesActives();

        if ($base <= 0) {
            return;
        }

        $pourcentageEffectif = $type === TypeRemise::Pourcentage
            ? $valeur
            : $valeur / $base * 100;

        $seuil = (float) config('inresto.remise_seuil_manager');

        if ($pourcentageEffectif <= $seuil) {
            return;
        }

        /** @var User $user */
        $user = User::query()->findOrFail($userId);

        if (! $user->isSuperAdmin() && ! $user->can('remise.autoriser_au_dela_seuil')) {
            throw RemiseException::seuilDepasse($seuil);
        }
    }
}
