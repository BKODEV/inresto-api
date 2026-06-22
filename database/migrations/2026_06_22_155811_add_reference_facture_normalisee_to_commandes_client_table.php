<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            // Référence d'une facture normalisée (FNE) émise hors de ce système ;
            // simple report sur la facture interne, jamais générée ici.
            $table->string('reference_facture_normalisee')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('commandes_client', function (Blueprint $table) {
            $table->dropColumn('reference_facture_normalisee');
        });
    }
};
