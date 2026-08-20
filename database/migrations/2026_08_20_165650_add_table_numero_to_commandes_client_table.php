<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numéro de table figé au moment de la commande.
 *
 * Les gabarits de facture et de reçu lisaient `$commande->table->numero` à
 * l'impression, donc la valeur du jour. Renommer une table réécrivait ainsi
 * toutes ses factures passées : une note de mars réimprimée en septembre
 * affichait le nouveau numéro, et ne correspondait plus au ticket remis au
 * client. Sur un contrôle ou un litige, le document ne prouve plus rien.
 *
 * La facture normalisée impose une piste d'audit inaltérable ; un document
 * imprimé doit rester identique à lui-même quel que soit l'état ultérieur du
 * plan de salle.
 *
 * Les commandes antérieures sont remplies avec le numéro actuel de leur table :
 * c'est la meilleure vérité disponible, et elle est exacte pour toutes celles
 * dont la table n'a jamais été renommée — soit la quasi-totalité.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            $table->string('table_numero', 50)->nullable()->after('table_id');
        });

        $this->remplirDepuisLesTables();
    }

    public function down(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            $table->dropColumn('table_numero');
        });
    }

    private function remplirDepuisLesTables(): void
    {
        DB::table('commandes_client')
            ->whereNull('table_numero')
            ->orderBy('id')
            ->chunkById(500, function ($commandes): void {
                $numeros = DB::table('tables_salle')
                    ->whereIn('id', $commandes->pluck('table_id')->unique())
                    ->pluck('numero', 'id');

                foreach ($commandes as $commande) {
                    DB::table('commandes_client')
                        ->where('id', $commande->id)
                        ->update(['table_numero' => $numeros[$commande->table_id] ?? null]);
                }
            });
    }
};
