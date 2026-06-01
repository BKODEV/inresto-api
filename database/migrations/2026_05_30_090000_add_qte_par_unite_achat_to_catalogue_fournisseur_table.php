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
        Schema::table('catalogue_fournisseur', function (Blueprint $table) {
            // Combien d'unités de stockage de la matière dans 1 unité d'achat de CE
            // fournisseur (ex. 1 cagette = 25 kg -> 25). Facteur concret propre au
            // couple fournisseur/matière. Seule conversion du système, appliquée à
            // la réception. default(1) = cas identité (unité d'achat = unité de stockage).
            $table->decimal('qte_par_unite_achat', 10, 3)->default(1)->after('prix_unitaire');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('catalogue_fournisseur', function (Blueprint $table) {
            $table->dropColumn('qte_par_unite_achat');
        });
    }
};
