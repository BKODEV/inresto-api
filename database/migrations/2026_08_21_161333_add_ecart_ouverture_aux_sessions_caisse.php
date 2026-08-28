<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qui manque — ou ce qui est en trop — entre deux services.
 *
 * Chaque session bouclait déjà correctement : fonds + ventes contre ce qu'on
 * compte. Mais la CHAÎNE restait ouverte. On fermait à 183 500, on rouvrait le
 * lendemain à 10 000, et personne ne disait combien était sorti ni pour aller
 * où. Les deux sessions se rapprochaient parfaitement même si 3 500
 * disparaissaient en route.
 *
 *   ecart_ouverture = fonds_initial − ( compté à la fermeture précédente
 *                                       − versements faits depuis )
 *
 * Zéro quand la transition est conforme : passage de main à main entre
 * caissiers, ou versement qui explique exactement la différence. Null quand il
 * n'y a rien à comparer — première session de la caisse, ou session précédente
 * fermée sans que le tiroir ait été compté.
 *
 * Figé à l'ouverture, comme les autres montants de rapprochement : un versement
 * enregistré plus tard ne doit pas réécrire l'histoire d'une session déjà
 * ouverte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->decimal('ecart_ouverture', 12, 2)->nullable()->after('fonds_initial');
        });

        // Les sessions déjà ouvertes n'ont jamais été comparées à la
        // précédente : leur transition reste inconnue, on ne l'invente pas.
        DB::table('sessions_caisse')->update(['ecart_ouverture' => null]);
    }

    public function down(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->dropColumn('ecart_ouverture');
        });
    }
};
