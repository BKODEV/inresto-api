<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui travaille sur quel poste de production.
 *
 * `postes_cuisine` reliait déjà des CATÉGORIES (ce qu'on y produit) ; ce pivot
 * ajoute les PERSONNES (qui le tient). Un utilisateur rattaché à au moins un
 * poste d'un point de vente est « de la cuisine » de ce point de vente — c'est
 * ce qui permet de proposer une brigade sans dérouler tout le personnel du
 * site, et sans exiger du chef une permission d'administration du personnel.
 *
 * Le rattachement est un ENSEMBLE reposé par `sync()`, comme les catégories :
 * l'organigramme se corrige en entier, il ne s'empile pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poste_cuisine_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poste_cuisine_id')->constrained('postes_cuisine')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['poste_cuisine_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poste_cuisine_user');
    }
};
