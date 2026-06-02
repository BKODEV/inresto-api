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
        Schema::create('lignes_fiche_technique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_technique_id')->constrained('fiches_techniques')->cascadeOnDelete();
            $table->foreignId('matiere_premiere_id')->constrained('matieres_premieres')->restrictOnDelete();
            $table->foreignId('unite_id')->constrained('unites_de_mesure')->restrictOnDelete();
            $table->decimal('quantite', 10, 3);
            $table->timestamps();

            $table->unique(['fiche_technique_id', 'matiere_premiere_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_fiche_technique');
    }
};
