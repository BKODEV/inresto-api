<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une matière ne peut figurer qu'une seule fois par commande : deux lignes
     * sur la même matière fausseraient la comparaison commandé/reçu du module
     * réception (cumul par matière) et donc la clôture de la commande.
     *
     * Les doublons hérités sont d'abord consolidés en une ligne unique
     * (quantités additionnées, prix moyen pondéré) afin de préserver le
     * `montant_previsionnel` déjà calculé.
     */
    public function up(): void
    {
        $this->consoliderDoublonsExistants();

        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->unique(
                ['commande_fournisseur_id', 'matiere_premiere_id'],
                'lignes_cf_commande_matiere_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->dropUnique('lignes_cf_commande_matiere_unique');
        });
    }

    /**
     * Fusionne les lignes en doublon (même commande, même matière) sur la plus
     * ancienne d'entre elles, puis supprime les autres.
     */
    private function consoliderDoublonsExistants(): void
    {
        $doublons = DB::table('lignes_commande_fournisseur')
            ->select('commande_fournisseur_id', 'matiere_premiere_id')
            ->groupBy('commande_fournisseur_id', 'matiere_premiere_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($doublons as $doublon) {
            $lignes = DB::table('lignes_commande_fournisseur')
                ->where('commande_fournisseur_id', $doublon->commande_fournisseur_id)
                ->where('matiere_premiere_id', $doublon->matiere_premiere_id)
                ->orderBy('id')
                ->get();

            $quantite = $lignes->sum(fn (object $ligne): float => (float) $ligne->quantite_commandee);
            $montant = $lignes->sum(fn (object $ligne): float => (float) $ligne->quantite_commandee * (float) $ligne->prix_unitaire_previsionnel);

            $conservee = $lignes->first();

            DB::table('lignes_commande_fournisseur')
                ->where('id', $conservee->id)
                ->update([
                    'quantite_commandee' => round($quantite, 3),
                    'prix_unitaire_previsionnel' => $quantite > 0
                        ? round($montant / $quantite, 4)
                        : $conservee->prix_unitaire_previsionnel,
                    'updated_at' => now(),
                ]);

            DB::table('lignes_commande_fournisseur')
                ->whereIn('id', $lignes->skip(1)->pluck('id')->all())
                ->delete();
        }
    }
};
