<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retrait d'une table du service, réversible et sans perte d'historique.
 *
 * La suppression dure reste réservée aux tables qui n'ont jamais servi
 * (`TableSalleService::supprimer()`) : dès qu'une commande existe, la table est
 * indéracinable. Sans archivage, une salle réaménagée traîne donc à vie ses
 * anciennes tables dans le plan, dans le sélecteur de la caisse et sur la
 * planche QR — dont les autocollants continuent de servir un menu.
 *
 * Colonne dédiée plutôt que `SoftDeletes` : le scope global d'Eloquent
 * exclurait la table de la relation `CommandeClient::table()`, or les gabarits
 * de facture et de reçu lisent `$commande->table->numero` à l'impression.
 * Archiver casserait la réimpression de tout l'historique de la table.
 *
 * Colonne dédiée plutôt que `statut` : ce dernier est piloté automatiquement
 * par le flux de commande (libre / occupée / à débarrasser), il écraserait un
 * état d'archivage au premier effet de bord.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables_salle', function (Blueprint $table) {
            $table->timestamp('archivee_le')->nullable()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('tables_salle', function (Blueprint $table) {
            $table->dropColumn('archivee_le');
        });
    }
};
