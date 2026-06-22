<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paiements d'une commande client. Plusieurs paiements possibles
     * (fractionnement). Le surpaiement est autorisé (monnaie rendue / pourboire).
     */
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commande_client_id')->constrained('commandes_client')->restrictOnDelete();
            $table->foreignId('mode_de_paiement_id')->constrained('modes_de_paiement')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('montant', 12, 2);
            $table->timestamp('date_heure')->useCurrent();
            $table->string('reference_externe')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
