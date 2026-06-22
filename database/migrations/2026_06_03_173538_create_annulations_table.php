<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Journal des annulations (polymorphe) : une commande entière ou une ligne.
     * `sort` (remise_en_stock | perte) n'est renseigné que pour l'annulation
     * d'une ligne déjà déstockée (commande servie) — il trace le devenir du
     * stock déjà déchargé.
     */
    public function up(): void
    {
        Schema::create('annulations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->morphs('annulable');
            $table->enum('sort', ['remise_en_stock', 'perte'])->nullable();
            $table->text('motif');
            $table->timestamp('date_heure')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annulations');
    }
};
