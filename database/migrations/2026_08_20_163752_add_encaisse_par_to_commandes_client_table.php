<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a clôturé la note, et quand.
 *
 * L'encaissement était le seul geste d'argent sans auteur enregistré : le
 * service recevait bien l'identifiant, mais le jetait. On pouvait le
 * reconstituer par les paiements liés — sauf pour une note soldée sans aucun
 * paiement (offerte, ou couverte par un avoir), où plus rien ne subsistait.
 *
 * Nullable : les commandes encaissées avant cette colonne n'ont pas d'auteur
 * connu, et inventer une valeur serait pire que l'absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            $table->foreignUuid('encaisse_par')->nullable()->after('statut')->constrained('users')->nullOnDelete();
            $table->timestamp('encaisse_le')->nullable()->after('encaisse_par');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            $table->dropConstrainedForeignId('encaisse_par');
            $table->dropColumn('encaisse_le');
        });
    }
};
