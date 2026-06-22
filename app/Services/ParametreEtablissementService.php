<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ParametreEtablissement;
use App\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Gère la fiche de paramètres d'un site (une seule par site) et son logo.
 */
class ParametreEtablissementService
{
    /**
     * Disque de stockage des logos d'établissement.
     */
    private const DISQUE_LOGO = 'public';

    /**
     * Crée ou met à jour la fiche du site (upsert par `site_id`) : un site n'a
     * qu'une fiche, donc `store` crée la première fois puis met à jour ensuite.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function creerOuMettreAJour(Site $site, array $donnees): ParametreEtablissement
    {
        return ParametreEtablissement::updateOrCreate(
            ['site_id' => $site->id],
            $donnees,
        );
    }

    /**
     * Enregistre le logo : écrase l'ancien fichier s'il existe, stocke le nouveau
     * sous `etablissements/{site_id}/logo.{ext}` et met à jour `logo_path`.
     */
    public function enregistrerLogo(ParametreEtablissement $parametre, UploadedFile $logo): ParametreEtablissement
    {
        $this->supprimerFichierLogo($parametre);

        $chemin = $logo->storeAs(
            "etablissements/{$parametre->site_id}",
            'logo.'.$logo->getClientOriginalExtension(),
            self::DISQUE_LOGO,
        );

        $parametre->logo_path = $chemin;
        $parametre->save();

        return $parametre;
    }

    /**
     * Supprime le logo : efface le fichier et remet `logo_path` à null.
     */
    public function supprimerLogo(ParametreEtablissement $parametre): ParametreEtablissement
    {
        $this->supprimerFichierLogo($parametre);

        $parametre->logo_path = null;
        $parametre->save();

        return $parametre;
    }

    private function supprimerFichierLogo(ParametreEtablissement $parametre): void
    {
        if ($parametre->logo_path !== null) {
            Storage::disk(self::DISQUE_LOGO)->delete($parametre->logo_path);
        }
    }
}
