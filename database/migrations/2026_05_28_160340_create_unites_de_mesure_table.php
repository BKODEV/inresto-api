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
        Schema::create('unites_de_mesure', function (Blueprint $table) {
            $table->id();
            $table->string('libelle');
            $table->string('symbole', 10); // kg, g, L, cl, pcs...
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unites_de_mesure');
    }
};
