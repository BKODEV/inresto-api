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
        Schema::create('sous_recettes', function (Blueprint $table) {
            $table->id();
            $table->string('libelle');
            $table->decimal('quantite_produite', 10, 3);
            $table->foreignId('unite_id')->constrained('unites_de_mesure')->restrictOnDelete();
            // Coût indicatif (PMP moyen multi-site), recalculé par le SousRecetteService.
            $table->decimal('cout_matiere', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sous_recettes');
    }
};
