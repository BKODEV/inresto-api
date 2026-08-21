<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pourquoi une caisse a été fermée sans que tout soit compté.
 *
 * La fermeture exige un compte par contenant, et c'est la bonne règle : sans
 * elle, on clôture sans avoir contrôlé. Mais une règle sans issue immobilise —
 * un terminal qui refuse d'imprimer son ticket, et la caisse ne se ferme plus,
 * donc le service suivant ne démarre pas.
 *
 * Le forçage est la porte de sortie, et elle est étroite : une permission
 * dédiée, et un motif écrit. Renseigné = la fermeture a été forcée ; les
 * contenants non comptés restent « non rapprochés », sans écart inventé.
 *
 * `ferme_par` dit déjà QUI a signé ; cette colonne dit pourquoi il a dû passer
 * outre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->text('motif_forcage')->nullable()->after('ferme_par');
        });
    }

    public function down(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->dropColumn('motif_forcage');
        });
    }
};
