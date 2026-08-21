<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quand la note est passée « servie ».
 *
 * Le cycle de vie vit sur les lignes depuis le service par tours, et seule
 * `lignes_commande_client.servie_le` était horodatée. La note, elle, n'avait
 * rien : impossible de dire depuis combien de temps une table attend de régler.
 *
 * Or c'est le seul signal dont dispose un serveur pour distinguer « le client
 * est au comptoir en train de payer » de « le client est parti sans payer ».
 * Symétrique d'`encaisse_le`, qui date déjà la clôture.
 *
 * Reprise depuis les lignes : l'instant où la note est devenue servie est celui
 * de sa DERNIÈRE ligne servie — avant, il restait quelque chose en cuisine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_client', function (Blueprint $table): void {
            $table->timestamp('servie_le')->nullable()->after('statut');
        });

        DB::table('commandes_client')
            ->whereIn('statut', ['servie', 'encaissee'])
            ->update([
                'servie_le' => DB::raw(
                    '(SELECT MAX(l.servie_le) FROM lignes_commande_client l
                      WHERE l.commande_client_id = commandes_client.id)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('commandes_client', function (Blueprint $table): void {
            $table->dropColumn('servie_le');
        });
    }
};
