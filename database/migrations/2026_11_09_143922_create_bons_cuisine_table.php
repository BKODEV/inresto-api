<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bons de cuisine : ce qu'un poste doit produire (ou ne plus produire) pour une
 * note, à un instant donné.
 *
 * Un bon = une note × un poste × un envoi. Il est FIGÉ à son émission : poste,
 * libellés, quantités et remarques y sont recopiés. Rattacher plus tard une
 * catégorie à un autre poste, renommer un plat ou corriger un poste ne doit pas
 * réécrire un papier déjà parti en cuisine — une réimpression doit sortir à
 * l'identique.
 *
 * Le bon ne dit rien de l'impression : c'est un document métier, que l'écran
 * comme l'imprimante pourront servir. Le suivi d'impression viendra s'y greffer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bons_cuisine', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commande_client_id')->constrained('commandes_client')->restrictOnDelete();
            $table->foreignId('point_de_vente_id')->constrained('points_de_vente')->restrictOnDelete();
            // Nullable : un point de vente sans aucun poste déclaré reçoit un bon
            // « Cuisine » unique. `nullOnDelete` : supprimer un poste n'efface pas
            // les bons qu'il a reçus, son libellé figé suffit à les relire.
            $table->foreignId('poste_cuisine_id')->nullable()->constrained('postes_cuisine')->nullOnDelete();
            $table->string('poste_libelle');
            // Numéro lisible en cuisine : repart à 1 chaque jour, par point de vente.
            $table->date('jour');
            $table->unsignedInteger('numero');
            $table->string('type');
            // Rang de ce bon parmi ceux du même envoi (« 2/3 ») : le poste sait que
            // la table attend aussi d'autres préparations.
            $table->unsignedSmallInteger('partie');
            $table->unsignedSmallInteger('parties');
            // Le serveur a ouvert la note, mais ce n'est pas forcément lui qui
            // envoie ou annule : on retient l'auteur du geste.
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('emis_le');
            $table->timestamps();

            $table->unique(['point_de_vente_id', 'jour', 'numero']);
            $table->index(['commande_client_id', 'emis_le']);
        });

        Schema::create('lignes_bon_cuisine', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bon_cuisine_id')->constrained('bons_cuisine')->cascadeOnDelete();
            $table->foreignId('ligne_commande_client_id')->constrained('lignes_commande_client')->restrictOnDelete();
            $table->string('produit_libelle');
            $table->unsignedInteger('quantite');
            $table->text('commentaire')->nullable();
            $table->timestamps();

            $table->index('ligne_commande_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_bon_cuisine');
        Schema::dropIfExists('bons_cuisine');
    }
};
