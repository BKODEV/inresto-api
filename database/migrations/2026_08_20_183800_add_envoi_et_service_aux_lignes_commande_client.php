<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cycle de vie porté par la LIGNE et non plus par la note.
 *
 * Une note ne servait qu'un seul tour : `ajouterLigne()` refusait tout ajout dès
 * l'envoi en cuisine, donc le dessert commandé après les plats n'avait nulle
 * part où aller. Or un repas se commande en plusieurs fois.
 *
 * `envoyee_le` horodate le départ en cuisine et sert d'identifiant de tour :
 * les lignes parties ensemble partagent la même valeur, ce qui permet à la
 * cuisine de distinguer un nouveau ticket de ceux déjà en production.
 *
 * `servie_le` est ce qui rend le multi-tour sûr. `servir()` déstockait TOUTES
 * les lignes actives de la note ; servir un second tour aurait redéstocké le
 * premier, et la dérive ne serait apparue qu'à l'inventaire. Une ligne déjà
 * horodatée n'est plus déstockée.
 *
 * Reprise de l'existant depuis le statut de la note, seule information
 * disponible : les lignes d'une note déjà partie en cuisine sont réputées
 * envoyées, celles d'une note servie ou encaissée réputées servies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_commande_client', function (Blueprint $table) {
            // Précision à la seconde, suffisante : deux tours d'une même note
            // sont séparés par le temps du service. Deux envois dans la même
            // seconde ne peuvent venir que d'un double appui, et les fusionner
            // en un seul ticket est sans conséquence pour la cuisine.
            $table->timestamp('envoyee_le')->nullable()->after('statut');
            $table->timestamp('servie_le')->nullable()->after('envoyee_le');

            // Le KDS interroge désormais les lignes, plus les commandes.
            $table->index(['envoyee_le', 'servie_le']);
        });

        $this->reprendreDepuisLeStatutDesNotes();
    }

    public function down(): void
    {
        Schema::table('lignes_commande_client', function (Blueprint $table) {
            $table->dropIndex(['envoyee_le', 'servie_le']);
            $table->dropColumn(['envoyee_le', 'servie_le']);
        });
    }

    /**
     * Les timestamps repris sont ceux de la note : on ne peut pas inventer
     * l'heure réelle de départ en cuisine d'une ligne antérieure.
     */
    private function reprendreDepuisLeStatutDesNotes(): void
    {
        $envoyees = ['envoyee_en_cuisine', 'servie', 'encaissee'];
        $servies = ['servie', 'encaissee'];

        DB::table('lignes_commande_client')
            ->whereIn('commande_client_id', DB::table('commandes_client')->whereIn('statut', $envoyees)->select('id'))
            ->update(['envoyee_le' => DB::raw('created_at')]);

        DB::table('lignes_commande_client')
            ->whereIn('commande_client_id', DB::table('commandes_client')->whereIn('statut', $servies)->select('id'))
            ->update(['servie_le' => DB::raw('updated_at')]);
    }
};
