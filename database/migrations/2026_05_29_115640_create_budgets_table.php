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
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->date('mois'); // premier jour du mois concerné
            $table->decimal('montant_alloue', 12, 2);
            $table->boolean('seuil_alerte_75')->default(true);
            $table->boolean('seuil_alerte_90')->default(true);
            $table->boolean('seuil_alerte_100')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'mois']);
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
