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
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_premiere_id')->constrained('matieres_premieres')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->decimal('stock_courant', 12, 3)->default(0);
            $table->decimal('pmp', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['matiere_premiere_id', 'site_id']);
            $table->index(['site_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
