<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des impressions de documents client (facture, reçu).
 *
 * La réimpression d'une note est un classique de la fraude en salle : le
 * document ressort, l'encaissement non. Les annulations et les remises portent
 * déjà leur auteur et leur motif ; l'impression était le seul geste sensible
 * qui ne laissait aucune trace.
 *
 * Volontairement limité aux documents d'argent remis au client. Journaliser les
 * impressions de fiches techniques ou de planches QR noierait le signal.
 *
 * Table en ajout seul : aucune mise à jour, aucune suppression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('type', ['facture', 'recu']);
            // Morph plutôt qu'une FK directe : ouvre le journal à d'autres
            // documents sans migration, sur le modèle de `annulations`.
            $table->morphs('imprimable');
            $table->timestamp('date_heure')->useCurrent();
            $table->timestamps();

            // Le journal se lit toujours par tranche de temps décroissante.
            $table->index('date_heure');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impressions');
    }
};
