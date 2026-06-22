<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table produits — définition PARTAGÉE (pas de site_id). Le prix de vente
     * n'est PAS porté ici : il vit sur le pivot carte_produit (un même produit
     * peut être vendu à des prix différents selon la carte).
     */
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categorie_id')->nullable()->constrained('categories_produit')->nullOnDelete();
            $table->string('libelle');
            $table->text('description')->nullable();
            // Marque qu'un produit est une FORMULE/MENU (offre commerciale composée
            // d'autres produits : ex. entrée + plat + dessert), par opposition à un
            // produit simple. À ce jour, simple drapeau : la COMPOSITION du menu
            // (produits/sections, choix proposés) n'est PAS encore modélisée.
            // Le jour où le besoin arrive : table de composition dédiée (ex.
            // menu_produit) + explosion à la vente = somme des fiches techniques
            // des composants choisis. NB : menu ≠ sous-recette (brique cuisine interne).
            $table->boolean('est_menu')->default(false);
            $table->string('photo')->nullable();
            $table->text('allergenes')->nullable();
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
