<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La méthodologie d'une fiche technique : comment on produit le plat.
 *
 * Trois tables filles plutôt qu'un champ texte : un intérimaire suit une liste
 * ordonnée, il ne relit pas un paragraphe. Et `cascadeOnDelete` comme les
 * lignes matière — la méthodologie appartient à SA version de fiche, jamais au
 * produit. Une nouvelle version la recopie, les versions archivées gardent la
 * leur (cf. « Versioning des fiches techniques »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapes_fiche_technique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_technique_id')->constrained('fiches_techniques')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->text('description');
            // Facultative : « laisser reposer » n'a pas toujours une durée qu'on
            // veuille chiffrer, et un zéro imposé se lirait comme « immédiat ».
            $table->unsignedSmallInteger('duree_minutes')->nullable();
            $table->timestamps();

            $table->unique(['fiche_technique_id', 'ordre']);
        });

        Schema::create('materiels_fiche_technique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_technique_id')->constrained('fiches_techniques')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            $table->string('libelle');
            $table->timestamps();

            $table->unique(['fiche_technique_id', 'ordre']);
        });

        Schema::create('points_critiques_fiche_technique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiche_technique_id')->constrained('fiches_techniques')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordre');
            // Enum fermé, comme partout ailleurs : « allergène », « allergene »
            // et « ALLERGENE » ne se recoupent pas au bout d'un mois, et un
            // point critique doit rester interrogeable.
            $table->string('type');
            $table->text('description');
            $table->timestamps();

            $table->unique(['fiche_technique_id', 'ordre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_critiques_fiche_technique');
        Schema::dropIfExists('materiels_fiche_technique');
        Schema::dropIfExists('etapes_fiche_technique');
    }
};
