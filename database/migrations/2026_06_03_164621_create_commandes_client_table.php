<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commande client (salle) : rattachée à une table et à une session de caisse
     * ouverte. `reservation_id` est stocké tel quel (table reservations non gérée
     * en V1, aucune contrainte FK). Les champs appartement sont une saisie
     * manuelle optionnelle (pas d'API hôtelière en V1).
     */
    public function up(): void
    {
        Schema::create('commandes_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained('tables_salle')->restrictOnDelete();
            $table->foreignId('session_caisse_id')->constrained('sessions_caisse')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('appartement_id')->nullable();
            $table->string('appartement_nom')->nullable();
            $table->boolean('appartement_a_verifier')->default(false);
            $table->timestamp('date_heure')->useCurrent();
            $table->enum('statut', ['ouverte', 'envoyee_en_cuisine', 'servie', 'encaissee', 'annulee'])->default('ouverte');
            $table->decimal('montant_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commandes_client');
    }
};
