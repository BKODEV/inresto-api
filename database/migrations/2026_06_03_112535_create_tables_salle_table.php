<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables physiques d'un point de vente. Le numéro est unique par PDV.
     */
    public function up(): void
    {
        Schema::create('tables_salle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->restrictOnDelete();
            $table->string('numero');
            $table->integer('capacite');
            $table->enum('statut', ['libre', 'occupee', 'reservee', 'a_debarrasser'])->default('libre');
            $table->timestamps();

            $table->unique(['point_de_vente_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tables_salle');
    }
};
