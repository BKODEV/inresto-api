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
        Schema::create('lignes_sous_recette', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sous_recette_id')->constrained('sous_recettes')->cascadeOnDelete();
            $table->foreignId('matiere_premiere_id')->constrained('matieres_premieres')->restrictOnDelete();
            $table->foreignId('unite_id')->constrained('unites_de_mesure')->restrictOnDelete();
            $table->decimal('quantite', 10, 3);
            $table->timestamps();

            // Une matière ne peut figurer qu'une fois par sous-recette.
            $table->unique(['sous_recette_id', 'matiere_premiere_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_sous_recette');
    }
};
