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
        Schema::create('catalogue_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fournisseur_id')
                ->constrained('fournisseurs')
                ->cascadeOnDelete();
            $table->foreignId('matiere_premiere_id')
                ->constrained('matieres_premieres')
                ->cascadeOnDelete();
            $table->decimal('prix_unitaire', 10, 2);
            $table->foreignId('unite_achat_id')
                ->constrained('unites_de_mesure')
                ->restrictOnDelete();
            $table->timestamps();

            $table->unique(['fournisseur_id', 'matiere_premiere_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogue_fournisseur');
    }
};
