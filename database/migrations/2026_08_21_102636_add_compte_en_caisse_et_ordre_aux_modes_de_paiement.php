<?php

declare(strict_types=1);

use App\Services\OrdreService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qualifie les modes de paiement : lesquels finissent dans le tiroir, et dans
 * quel ordre les présenter.
 *
 * `compte_en_caisse` corrige le rapprochement de fermeture. `montant_theorique`
 * valait `fonds_initial + Σ TOUS les paiements`, alors que `montant_reel`
 * demande le montant réellement COMPTÉ — le tiroir, qui ne contient que les
 * espèces. Chaque virement creusait donc un manquant qui n'existait pas, et le
 * système ne pouvait pas s'en sortir seul : la table ne portait que `libelle` et
 * `statut`, rien ne disait la nature d'un mode.
 *
 * Défaut à `true` : le comportement d'avant est préservé, et deviner la nature
 * d'un mode depuis son libellé serait fragile. Chaque établissement décoche
 * ensuite ses modes hors tiroir dans Paramètres → Modes de paiement.
 *
 * `ordre` sert deux choses : l'ordre des boutons à la caisse, et le
 * départage entre plusieurs modes en tiroir pour la présélection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modes_de_paiement', function (Blueprint $table): void {
            $table->boolean('compte_en_caisse')->default(true)->after('libelle');
            $table->unsignedInteger('ordre')->default(0)->after('compte_en_caisse');
        });

        $this->numeroterExistants();
    }

    public function down(): void
    {
        Schema::table('modes_de_paiement', function (Blueprint $table): void {
            $table->dropColumn(['compte_en_caisse', 'ordre']);
        });
    }

    /**
     * Rangs distincts à partir de 1 : {@see OrdreService} suppose
     * qu'aucun élément ne partage sa place, et laisser toute la table à 0
     * rendrait le premier décalage incohérent.
     */
    private function numeroterExistants(): void
    {
        $rang = 1;

        foreach (DB::table('modes_de_paiement')->orderBy('id')->pluck('id') as $id) {
            DB::table('modes_de_paiement')->where('id', $id)->update(['ordre' => $rang++]);
        }
    }
};
