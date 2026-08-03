<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UserException;
use App\Mail\ResetPasswordLinkMail;
use App\Mail\WelcomeUserMail;
use App\Models\Role;
use App\Models\User;
use App\Support\MailConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Logique métier du module Utilisateurs : création/édition, synchronisation des
 * rôles et des sites, gestion des mots de passe (saisi, généré+email, reset),
 * activation/désactivation et suppression douce.
 *
 * Centralise les garde-fous de sécurité : hiérarchie anti-escalade, périmètre
 * site et protection du dernier super-admin actif. Le super-admin court-circuite
 * toute la hiérarchie et tout le périmètre.
 */
class UserService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function creer(User $acteur, array $data): User
    {
        $roles = $data['roles'] ?? [];
        $sites = $data['sites'] ?? [];

        $this->assertPeutAssignerRoles($acteur, $roles);
        $this->assertSitesDansPerimetre($acteur, $sites);

        [$motDePasse, $envoyerBienvenue] = $this->resoudreMotDePasse($data);

        return DB::transaction(function () use ($acteur, $data, $roles, $sites, $motDePasse, $envoyerBienvenue) {
            $user = User::create([
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'identifiant' => $data['identifiant'],
                'email' => $data['email'] ?? null,
                'telephone' => $data['telephone'] ?? null,
                'pin_code' => $data['pin_code'] ?? null,
                'password' => Hash::make($motDePasse),
                'statut' => $data['statut'] ?? 'actif',
                'created_by' => $acteur->getKey(),
                'updated_by' => $acteur->getKey(),
            ]);

            $user->syncRoles($roles);
            $user->sites()->sync($sites);

            if ($envoyerBienvenue) {
                Mail::to($user->email)->send(new WelcomeUserMail($user, $motDePasse));
            }

            return $user->load(['roles', 'sites']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function mettreAJour(User $acteur, User $cible, array $data): User
    {
        $this->assertPeutGerer($acteur, $cible);

        if (array_key_exists('roles', $data)) {
            $this->assertPeutAssignerRoles($acteur, $data['roles']);
        }

        if (array_key_exists('sites', $data)) {
            $this->assertSitesDansPerimetre($acteur, $data['sites']);
        }

        return DB::transaction(function () use ($acteur, $cible, $data) {
            $cible->fill([
                'nom' => $data['nom'] ?? $cible->nom,
                'prenom' => $data['prenom'] ?? $cible->prenom,
                'identifiant' => $data['identifiant'] ?? $cible->identifiant,
                'email' => array_key_exists('email', $data) ? $data['email'] : $cible->email,
                'telephone' => array_key_exists('telephone', $data) ? $data['telephone'] : $cible->telephone,
                'pin_code' => array_key_exists('pin_code', $data) ? $data['pin_code'] : $cible->pin_code,
                'statut' => $data['statut'] ?? $cible->statut,
                'updated_by' => $acteur->getKey(),
            ]);
            $cible->save();

            if (array_key_exists('roles', $data)) {
                $cible->syncRoles($data['roles']);
            }

            if (array_key_exists('sites', $data)) {
                $cible->sites()->sync($data['sites']);
            }

            return $cible->load(['roles', 'sites']);
        });
    }

    public function supprimer(User $acteur, User $cible): void
    {
        $this->assertPeutGerer($acteur, $cible);
        $this->assertPasDernierSuperAdmin($cible);

        $cible->forceFill(['updated_by' => $acteur->getKey()])->save();
        $cible->delete();
    }

    public function activer(User $acteur, User $cible): User
    {
        $this->assertPeutGerer($acteur, $cible);

        $cible->forceFill(['statut' => 'actif', 'updated_by' => $acteur->getKey()])->save();

        return $cible->load(['roles', 'sites']);
    }

    public function desactiver(User $acteur, User $cible): User
    {
        $this->assertPeutGerer($acteur, $cible);
        $this->assertPasDernierSuperAdmin($cible);

        $cible->forceFill(['statut' => 'inactif', 'updated_by' => $acteur->getKey()])->save();

        return $cible->load(['roles', 'sites']);
    }

    /**
     * Mode a : l'admin définit directement un nouveau mot de passe.
     */
    public function reinitialiserMotDePasse(User $acteur, User $cible, string $nouveau): void
    {
        $this->assertPeutGerer($acteur, $cible);

        $cible->forceFill([
            'password' => Hash::make($nouveau),
            'updated_by' => $acteur->getKey(),
        ])->save();
    }

    /**
     * Mode b : l'admin déclenche un lien de réinitialisation envoyé par email.
     * Réutilise le Password broker de Laravel (token signé/expirable).
     */
    public function envoyerLienReset(User $acteur, User $cible): void
    {
        $this->assertPeutGerer($acteur, $cible);

        if (! $cible->email) {
            throw UserException::emailRequisPourLien();
        }

        if (! MailConfig::isConfigured()) {
            throw UserException::emailNonConfigure();
        }

        $token = Password::broker()->createToken($cible);

        Mail::to($cible->email)->send(new ResetPasswordLinkMail($cible, $token));
    }

    /**
     * Détermine le mot de passe à poser et s'il faut envoyer le mail de bienvenue.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: bool} [motDePasse, envoyerMailBienvenue]
     */
    private function resoudreMotDePasse(array $data): array
    {
        $saisi = $data['password'] ?? null;

        if (is_string($saisi) && $saisi !== '') {
            return [$saisi, false];
        }

        // Aucun mot de passe saisi : email requis + SMTP configuré, puis génération.
        if (empty($data['email'])) {
            throw UserException::emailRequis();
        }

        if (! MailConfig::isConfigured()) {
            throw UserException::emailNonConfigure();
        }

        return [Str::password(16), true];
    }

    /**
     * Hiérarchie + périmètre : l'acteur peut-il gérer la cible ?
     */
    private function assertPeutGerer(User $acteur, User $cible): void
    {
        if ($acteur->isSuperAdmin()) {
            return;
        }

        if ($cible->niveauMax() >= $acteur->niveauMax()) {
            throw UserException::hierarchieInsuffisante();
        }

        if (! $acteur->partageSiteAvec($cible)) {
            throw UserException::horsPerimetre();
        }
    }

    /**
     * Anti-escalade : on ne peut assigner que des rôles de niveau < au sien.
     *
     * @param  array<int, string|int>  $roles  noms (ou ids) des rôles
     */
    private function assertPeutAssignerRoles(User $acteur, array $roles): void
    {
        if ($acteur->isSuperAdmin() || $roles === []) {
            return;
        }

        $niveauActeur = $acteur->niveauMax();

        $niveauMaxDemande = Role::query()
            ->whereIn('name', $roles)
            ->max('niveau');

        if ($niveauMaxDemande !== null && (int) $niveauMaxDemande >= $niveauActeur) {
            throw UserException::roleNiveauTropEleve();
        }
    }

    /**
     * Périmètre : un non-super-admin ne rattache qu'à ses propres sites.
     *
     * Le cas du tableau vide n'est plus atteignable par l'API (les FormRequests
     * exigent au moins un site) ; il reste toléré ici comme garde défensive
     * pour les appels internes qui ne passeraient pas par la validation.
     *
     * @param  array<int, int>  $siteIds
     */
    private function assertSitesDansPerimetre(User $acteur, array $siteIds): void
    {
        if ($acteur->isSuperAdmin() || $siteIds === []) {
            return;
        }

        $autorises = $acteur->sites->pluck('id')->all();

        foreach ($siteIds as $siteId) {
            if (! in_array((int) $siteId, array_map('intval', $autorises), true)) {
                throw UserException::siteHorsPerimetre();
            }
        }
    }

    /**
     * Empêche la suppression/désactivation du dernier super-admin actif (lock-out).
     */
    private function assertPasDernierSuperAdmin(User $cible): void
    {
        if (! $cible->isSuperAdmin()) {
            return;
        }

        $autresActifs = User::query()
            ->where('statut', 'actif')
            ->where('id', '!=', $cible->getKey())
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->exists();

        if (! $autresActifs) {
            throw UserException::dernierSuperAdmin();
        }
    }
}
