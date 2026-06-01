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
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matiere_premiere_id')
                ->constrained('matieres_premieres')
                ->restrictOnDelete();
            $table->foreignUuid('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete(); // obligatoire pour mouvements manuels, null si auto

            $table->enum('type', [
                'entree_reception',
                'sortie_vente',
                'perte',
                'casse',
                'offert',
                'repas_personnel',
                'correction_inventaire',
            ]);

            $table->decimal('quantite', 12, 3); // toujours positive ; le sens dépend du type
            $table->decimal('pmp_au_moment', 12, 4)->default(0); // valorisation (coût unitaire) à l'instant T

            $table->timestamp('date_mouvement')->useCurrent();

            // Référence polymorphe vers l'origine (reception, ligne_commande_client, inventaire...)
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('site_id')->after('matiere_premiere_id')->constrained('sites')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'matiere_premiere_id', 'date_mouvement']);
            $table->index(['matiere_premiere_id', 'date_mouvement']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
