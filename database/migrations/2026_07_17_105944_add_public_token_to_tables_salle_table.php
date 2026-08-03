<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jeton public opaque porté par la TABLE (et non par le point de vente) : il
 * identifie la table scannée sans jamais exposer d'id interne. Nullable pour
 * permettre le backfill des tables existantes (`inresto:backfill-table-tokens`),
 * puis renseigné à la création de toute nouvelle table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables_salle', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('statut');
        });
    }

    public function down(): void
    {
        Schema::table('tables_salle', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
