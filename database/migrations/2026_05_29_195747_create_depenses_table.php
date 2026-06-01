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
        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->restrictOnDelete();
            $table->foreignId('reception_id')->nullable()->constrained('receptions')->nullOnDelete();
            $table->enum('type', ['achat_fournisseur', 'charge_hors_stock']);
            $table->string('libelle');
            $table->decimal('montant', 12, 2);
            $table->date('date_depense');
            $table->timestamps();

            $table->index(['budget_id', 'date_depense']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('depenses');
    }
};
