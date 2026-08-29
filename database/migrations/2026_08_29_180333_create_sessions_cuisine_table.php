<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le service de la brigade — le pendant cuisine de la session de caisse.
 *
 * Rattachée au POINT DE VENTE et non à une caisse, et INDÉPENDANTE de la
 * session de caisse. Trois raisons :
 *  - un point de vente porte plusieurs caisses pour une seule cuisine : coupler
 *    obligerait à désigner arbitrairement laquelle commande le service ;
 *  - la brigade travaille avant la première ouverture de caisse (mise en place)
 *    et après la dernière fermeture (nettoyage) — ces heures disparaîtraient ;
 *  - une caisse fermée par erreur fermerait la cuisine en plein service.
 *
 * Ce qu'elle apporte : savoir quelle ÉQUIPE a produit quoi. La brigade est un
 * pivot, pas une colonne — un service se tient à plusieurs, et le seul
 * `ouvert_par` ne dirait que qui a tourné la clé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions_cuisine', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->restrictOnDelete();
            $table->foreignUuid('ouvert_par')->constrained('users')->restrictOnDelete();
            $table->timestamp('ouvert_le')->useCurrent();
            $table->foreignUuid('ferme_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ferme_le')->nullable();
            // Un libellé de service (« Midi », « Soir », « Brunch ») : la même
            // brigade tient rarement les deux services d'une journée, et un
            // rapport qui n'affiche que des horodatages se lit mal.
            $table->string('libelle')->nullable();
            $table->text('notes')->nullable();
            $table->string('statut')->default('ouverte');
            $table->timestamps();
        });

        // Une seule cuisine ouverte à la fois par point de vente — même règle
        // que pour la caisse, et pour la même raison : deux services ouverts
        // rendraient le rattachement des lignes ambigu.
        DB::statement(
            "CREATE UNIQUE INDEX sessions_cuisine_une_ouverte_par_pdv
             ON sessions_cuisine (point_de_vente_id) WHERE statut = 'ouverte'"
        );

        Schema::create('session_cuisine_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_cuisine_id')->constrained('sessions_cuisine')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['session_cuisine_id', 'user_id']);
        });

        Schema::table('lignes_commande_client', function (Blueprint $table): void {
            // Posé à l'envoi en cuisine, jamais à la création de la ligne : une
            // ligne au brouillon n'a encore été produite par personne.
            //
            // Nullable et `nullOnDelete` : les lignes antérieures à ce module
            // n'ont pas de service, et on ne le leur invente pas.
            $table->foreignId('session_cuisine_id')
                ->nullable()
                ->after('envoyee_le')
                ->constrained('sessions_cuisine')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lignes_commande_client', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('session_cuisine_id');
        });

        Schema::dropIfExists('session_cuisine_user');
        DB::statement('DROP INDEX IF EXISTS sessions_cuisine_une_ouverte_par_pdv');
        Schema::dropIfExists('sessions_cuisine');
    }
};
