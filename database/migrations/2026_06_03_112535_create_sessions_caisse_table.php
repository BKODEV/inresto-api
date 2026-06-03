<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sessions de caisse (ouverture/fermeture, contrôle d'écart). Une seule
     * session `ouverte` par caisse à la fois : garanti par un index unique
     * PARTIEL (statut = 'ouverte'). La syntaxe `CREATE UNIQUE INDEX ... WHERE`
     * est supportée par PostgreSQL ET SQLite (donc valable aussi en test).
     */
    public function up(): void
    {
        Schema::create('sessions_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caisse_id')->constrained('caisses')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('ouvert_le')->useCurrent();
            $table->decimal('fonds_initial', 12, 2)->default(0);
            $table->timestamp('ferme_le')->nullable();
            $table->decimal('montant_theorique', 12, 2)->nullable();
            $table->decimal('montant_reel', 12, 2)->nullable();
            $table->decimal('ecart', 12, 2)->nullable();
            $table->enum('statut', ['ouverte', 'fermee'])->default('ouverte');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "CREATE UNIQUE INDEX sessions_caisse_une_ouverte_par_caisse
             ON sessions_caisse (caisse_id) WHERE statut = 'ouverte'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions_caisse');
    }
};
