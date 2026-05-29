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
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('point_de_vente_id')
                ->constrained('points_de_vente')
                ->restrictOnDelete();
            $table->date('mois'); // premier jour du mois concerné
            $table->decimal('montant_alloue', 12, 2);
            $table->boolean('seuil_alerte_75')->default(true);
            $table->boolean('seuil_alerte_90')->default(true);
            $table->boolean('seuil_alerte_100')->default(true);
            $table->timestamps();

            $table->unique(['point_de_vente_id', 'mois']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
