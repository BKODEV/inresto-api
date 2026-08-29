<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quelles catégories de produit relèvent de quel poste.
 *
 * Un PIVOT, et non une clé étrangère sur `categories_produit` : la catégorie
 * est une définition PARTAGÉE (aucun `site_id`, cf. « Définitions partagées vs
 * opérations rattachées au site »), alors qu'un poste appartient à un point de
 * vente. Accrocher la catégorie au poste par une colonne ferait porter à une
 * définition partagée une décision d'exploitation locale.
 *
 * Le pivot lève la contradiction et rend service au passage : la même catégorie
 * « Cocktails » peut relever du bar au restaurant et du chaud au rooftop.
 *
 * Une catégorie SANS rattachement n'est pas une erreur bloquante — elle est
 * montrée à tous les postes. Voir la règle de sûreté dans le contrôleur KDS :
 * une configuration incomplète doit dégrader vers « tout le monde le voit »,
 * jamais vers « personne ne le voit ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_poste_cuisine', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('categorie_produit_id')->constrained('categories_produit')->cascadeOnDelete();
            $table->foreignId('poste_cuisine_id')->constrained('postes_cuisine')->cascadeOnDelete();

            $table->unique(['categorie_produit_id', 'poste_cuisine_id'], 'categorie_poste_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_poste_cuisine');
    }
};
