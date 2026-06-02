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
        Schema::create('lignes_inventaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventaire_id')->constrained('inventaires')->cascadeOnDelete();
            $table->foreignId('matiere_premiere_id')->constrained('matieres_premieres')->restrictOnDelete();
            // Indicatif d'aide à la saisie (stock_courant lu) — figé à la clôture.
            $table->decimal('quantite_theorique', 10, 3)->default(0);
            $table->decimal('quantite_physique', 10, 3);
            // Calculé à la clôture : physique - théorique réel lu sous verrou.
            $table->decimal('ecart', 10, 3)->nullable();
            $table->timestamps();

            $table->unique(['inventaire_id', 'matiere_premiere_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_inventaire');
    }
};
