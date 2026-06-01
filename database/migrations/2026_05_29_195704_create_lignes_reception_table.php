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
        Schema::create('lignes_reception', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_id')->constrained('receptions')->cascadeOnDelete();
            $table->foreignId('matiere_premiere_id')->constrained('matieres_premieres')->restrictOnDelete();
            $table->foreignId('unite_id')->constrained('unites_de_mesure')->restrictOnDelete();
            $table->decimal('quantite_recue', 12, 3);
            $table->decimal('prix_unitaire_reel', 12, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lignes_reception');
    }
};
