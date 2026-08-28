<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postes de production : « Entrées froides », « Chaud », « Bar », « Dessert ».
 *
 * POURQUOI une table plutôt que réutiliser les catégories de produit.
 *
 * `categories_produit` est une taxonomie COMMERCIALE — sa colonne `ordre`
 * range les sections d'une carte pour le client. La brigade est une taxonomie
 * de PRODUCTION : qui cuit quoi. Les deux se ressemblent mais n'ont ni la même
 * cardinalité (une carte porte quinze catégories, un pass compte trois postes)
 * ni le même propriétaire : « Cocktails » et « Softs » sont deux sections de
 * carte pour un seul bar.
 *
 * Le système n'apprend donc PAS ce qu'est une entrée — il ne peut pas, les
 * catégories étant saisies par l'établissement. Il laisse le restaurant
 * déclarer ses postes une fois, et y accrocher ses propres catégories.
 *
 * Rattaché au POINT DE VENTE et non au site : un rooftop et un restaurant
 * n'ont pas le même pass. C'est cohérent avec le KDS, déjà interrogé par PDV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postes_cuisine', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->cascadeOnDelete();
            $table->string('libelle');
            // Ordre des onglets à l'écran : entrées avant plats avant desserts.
            $table->unsignedInteger('ordre')->default(0);
            $table->enum('statut', ['actif', 'inactif'])->default('actif');
            $table->timestamps();

            // Deux postes homonymes sur un même pass seraient indiscernables à
            // l'écran, et le rattachement des catégories deviendrait un pari.
            $table->unique(['point_de_vente_id', 'libelle']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postes_cuisine');
    }
};
