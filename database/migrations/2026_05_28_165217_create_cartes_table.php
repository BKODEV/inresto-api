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
        Schema::create('cartes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_de_vente_id')
                ->constrained('points_de_vente')
                ->restrictOnDelete();
            $table->string('libelle');
            $table->enum('statut', ['brouillon', 'active', 'archivee'])->default('brouillon');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cartes');
    }
};
