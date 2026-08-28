<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quand la cuisine a fini le plat — distinct du moment où la salle le pose.
 *
 * `servie_le` portait deux faits à la fois : « c'est cuit » et « le client l'a
 * devant lui ». Un seul horodatage pour deux gestes qui ne sont ni au même
 * moment, ni faits par la même personne — la cuisine annonce au pass, la salle
 * emporte. Confondus, le chef ne peut pas mesurer son temps de production : le
 * chrono du KDS comptait aussi le temps où l'assiette attendait sous la lampe,
 * qui n'est pas de sa responsabilité.
 *
 * Séparés, les deux durées se lisent enfin :
 *   — `envoyee_le` → `prete_le` : le temps de la cuisine ;
 *   — `prete_le` → `servie_le` : le temps d'attente au pass, celui de la salle.
 *
 * DÉSTOCKAGE : il reste sur `servie_le`, jamais ici. `prete_le` est un signal
 * de cuisine, donc RÉVERSIBLE — on dévalide un plat annoncé trop tôt. Y
 * accrocher les mouvements de stock les ferait battre au rythme des corrections
 * du pass. Le stock ne bouge que sur l'irréversible.
 *
 * Reprise de l'existant : une ligne servie était forcément prête avant. Faute
 * de pouvoir inventer l'heure réelle de l'annonce, on la date au service —
 * l'histoire affiche donc un temps de pass nul, ce qui est la seule lecture
 * honnête d'une donnée qu'on n'a jamais collectée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande_client', function (Blueprint $table): void {
            $table->timestamp('prete_le')->nullable()->after('envoyee_le');

            // La file du pass : prête et pas encore emportée.
            $table->index(['prete_le', 'servie_le']);
        });

        DB::table('lignes_commande_client')
            ->whereNotNull('servie_le')
            ->update(['prete_le' => DB::raw('servie_le')]);
    }

    public function down(): void
    {
        Schema::table('lignes_commande_client', function (Blueprint $table): void {
            $table->dropIndex(['prete_le', 'servie_le']);
            $table->dropColumn('prete_le');
        });
    }
};
