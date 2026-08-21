<?php

declare(strict_types=1);

use App\Models\Stock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tolérance d'écart de caisse, par établissement.
 *
 * Avec de la petite monnaie on ne tombe jamais pile : l'écran de fermeture
 * alertait donc en rouge sur cinq francs comme sur cinquante mille. En dessous
 * de ce montant, l'écart est réputé normal.
 *
 * `0` = règle désactivée, tout écart non nul est signalé — même convention que
 * `matieres_premieres.seuil_alerte_stock` ({@see Stock::estSousSeuil()}).
 * Par site, comme la devise : deux établissements n'ont ni la même monnaie ni
 * le même volume d'espèces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres_etablissement', function (Blueprint $table): void {
            $table->decimal('tolerance_ecart_caisse', 12, 2)->default(0)->after('devise');
        });
    }

    public function down(): void
    {
        Schema::table('parametres_etablissement', function (Blueprint $table): void {
            $table->dropColumn('tolerance_ecart_caisse');
        });
    }
};
