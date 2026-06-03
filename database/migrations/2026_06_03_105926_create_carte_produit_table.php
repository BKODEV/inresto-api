<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot carte_produit : un produit figurant sur une carte, avec son
     * prix_vente PROPRE à cette carte. Le prix de vente est une décision
     * commerciale contextuelle — il n'est pas porté par le produit.
     */
    public function up(): void
    {
        Schema::create('carte_produit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carte_id')->constrained('cartes')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->decimal('prix_vente', 10, 2);
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();

            $table->unique(['carte_id', 'produit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carte_produit');
    }
};
