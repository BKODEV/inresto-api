<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le poste qui reçoit ce que personne d'autre ne produit.
 *
 * À l'écran, une catégorie rattachée à aucun poste est montrée à TOUS les
 * postes : mieux vaut un plat vu deux fois qu'un plat jamais vu. Sur papier, la
 * même règle imprime le plat sur chaque bon, et deux postes le cuisent. Le bon
 * de cuisine exige donc une destination unique : le poste par défaut.
 *
 * Un seul par point de vente, garanti par un index partiel. Les postes déjà
 * déclarés en reçoivent un : le premier dans l'ordre d'affichage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postes_cuisine', function (Blueprint $table): void {
            $table->boolean('par_defaut')->default(false)->after('ordre');
        });

        DB::statement(
            'CREATE UNIQUE INDEX postes_cuisine_un_defaut_par_pdv
             ON postes_cuisine (point_de_vente_id) WHERE par_defaut'
        );

        $premiers = DB::table('postes_cuisine')
            ->orderBy('point_de_vente_id')
            ->orderByRaw("CASE WHEN statut = 'actif' THEN 0 ELSE 1 END")
            ->orderBy('ordre')
            ->orderBy('id')
            ->get(['id', 'point_de_vente_id'])
            ->unique('point_de_vente_id')
            ->pluck('id');

        DB::table('postes_cuisine')->whereIn('id', $premiers)->update(['par_defaut' => true]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS postes_cuisine_un_defaut_par_pdv');

        Schema::table('postes_cuisine', function (Blueprint $table): void {
            $table->dropColumn('par_defaut');
        });
    }
};
