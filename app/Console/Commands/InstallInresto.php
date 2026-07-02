<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SiteSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Installateur produit : sur une instance neuve, met en place les permissions,
 * les rôles système, le site par défaut, puis crée le PREMIER super-admin.
 *
 * Single-tenant : chaque déploiement est une instance isolée ; cette commande
 * est l'étape « prêt à l'emploi » qui suit `php artisan migrate`.
 *
 * Idempotent : relançable sans casser une instance déjà installée (les seeders
 * sont idempotents et le super-admin est créé via updateOrCreate sur l'identifiant).
 *
 * Usage interactif :
 *   php artisan inresto:install
 * Usage non interactif (CI / provisioning) :
 *   php artisan inresto:install --identifiant=admin --password=Secret@2026 \
 *     --nom=Admin --prenom=Root --email=admin@exemple.com --no-interaction
 */
#[Signature('inresto:install
    {--identifiant= : Identifiant de connexion du super-admin}
    {--password= : Mot de passe du super-admin}
    {--nom= : Nom du super-admin}
    {--prenom= : Prénom du super-admin}
    {--email= : Email du super-admin (optionnel)}')]
#[Description('Installe une instance neuve : permissions, rôles système, site par défaut et premier super-admin.')]
class InstallInresto extends Command
{
    public function handle(): int
    {
        $this->info('Installation d\'InResto…');

        $this->callSilent('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
        $this->components->task('Permissions', fn () => true);

        $this->callSilent('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
        $this->components->task('Rôles système', fn () => true);

        $this->callSilent('db:seed', ['--class' => SiteSeeder::class, '--force' => true]);
        $this->components->task('Site par défaut', fn () => true);

        $this->creerSuperAdmin();

        $this->newLine();
        $this->info('Installation terminée. Connectez-vous avec votre identifiant super-admin.');

        return self::SUCCESS;
    }

    private function creerSuperAdmin(): void
    {
        $identifiant = $this->resolveValue('identifiant', 'Identifiant du super-admin', 'superadmin');
        $nom = $this->resolveValue('nom', 'Nom', 'Super');
        $prenom = $this->resolveValue('prenom', 'Prénom', 'Admin');
        $email = $this->option('email')
            ?: ($this->input->isInteractive() ? text('Email (optionnel)', required: false) : null);
        $motDePasse = $this->resolvePassword();

        $user = User::query()->updateOrCreate(
            ['identifiant' => $identifiant],
            [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $email ?: null,
                'password' => Hash::make($motDePasse),
                'statut' => 'actif',
            ],
        );

        $user->syncRoles([Role::SUPER_ADMIN]);

        $site = Site::query()->firstOrCreate(
            ['code' => 'PRINCIPAL'],
            ['nom' => 'Site principal', 'statut' => 'actif'],
        );
        $user->sites()->syncWithoutDetaching([$site->id]);

        $this->components->task("Super-admin « {$identifiant} »", fn () => true);
    }

    private function resolveValue(string $option, string $label, string $defaut): string
    {
        $value = $this->option($option);

        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($this->input->isInteractive()) {
            return text($label, default: $defaut, required: true);
        }

        return $defaut;
    }

    private function resolvePassword(): string
    {
        $value = $this->option('password');

        if ($value !== null && $value !== '') {
            return $value;
        }

        if ($this->input->isInteractive()) {
            return password('Mot de passe du super-admin', required: true);
        }

        // Non interactif sans mot de passe fourni : on en génère un et on l'affiche.
        $genere = Str::password(16);
        $this->warn("Aucun mot de passe fourni — mot de passe généré : {$genere}");

        return $genere;
    }
}
