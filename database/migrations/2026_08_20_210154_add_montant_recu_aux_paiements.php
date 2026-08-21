<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espèces tendues par le client, distinctes du montant encaissé.
 *
 * `montant` est ce qui RESTE en tiroir : c'est lui qui alimente
 * `montant_theorique` de la session, donc l'écart de fermeture. Y inscrire le
 * billet tendu gonflerait le théorique de la monnaie rendue, et ferait
 * apparaître un manquant chaque soir.
 *
 * Nullable : une carte ou un virement ne rendent pas de monnaie, et les
 * paiements déjà enregistrés n'ont jamais porté cette information.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table): void {
            $table->decimal('montant_recu', 12, 2)->nullable()->after('montant');
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table): void {
            $table->dropColumn('montant_recu');
        });
    }
};
