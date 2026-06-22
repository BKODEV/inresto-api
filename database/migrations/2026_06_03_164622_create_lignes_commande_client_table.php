<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ligne de commande client. `prix_unitaire` est figé au prix de la carte au
     * moment de la vente. `fiche_technique_id` est figé au passage « servie »
     * (version de fiche réellement déstockée). `statut` = inactif si annulée.
     */
    public function up(): void
    {
        Schema::create('lignes_commande_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_client_id')->constrained('commandes_client')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->foreignId('fiche_technique_id')->nullable()->constrained('fiches_techniques')->nullOnDelete();
            $table->integer('quantite')->default(1);
            $table->decimal('prix_unitaire', 10, 2);
            $table->text('commentaire')->nullable();
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_commande_client');
    }
};
