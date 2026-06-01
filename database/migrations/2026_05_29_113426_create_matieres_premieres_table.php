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
        Schema::create('matieres_premieres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categorie_id')
                ->constrained('categories_matiere_premiere')
                ->restrictOnDelete();
            $table->foreignId('unite_de_mesure_id')
                ->constrained('unites_de_mesure')
                ->restrictOnDelete();
            $table->string('libelle');
            $table->decimal('seuil_alerte_stock', 12, 3)->default(0);
            $table->boolean('gestion_peremption')->default(false);

            // Définition GLOBALE : le stock (stock_courant + pmp) est par site et
            // vit désormais dans la table `stocks`, pas ici.
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matieres_premieres');
    }
};
