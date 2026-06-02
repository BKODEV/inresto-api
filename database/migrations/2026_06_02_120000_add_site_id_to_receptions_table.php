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
        Schema::table('receptions', function (Blueprint $table) {
            // La cuisine/site qui reçoit physiquement la marchandise. Le stock
            // entre sur CE site. La commande fournisseur, elle, reste un acte
            // d'achat partagé sans site_id : c'est la réception qui tranche.
            $table->foreignId('site_id')
                ->after('commande_fournisseur_id')
                ->constrained('sites')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
