<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un bon en attente peut perdre son objet avant d'être imprimé : le plat a été
 * servi sans lui, ou annulé. L'imprimer quand même ferait cuire une seconde
 * fois ce qui est déjà parti.
 *
 * Rien n'est supprimé — le bon garde la trace de ce qui a été envoyé :
 *  - `lignes_bon_cuisine.sans_objet` : ce plat ne sera pas imprimé ;
 *  - `bons_cuisine.sans_objet_le` : plus aucun plat à imprimer, le bon quitte
 *    la file.
 *
 * Ne concerne que les bons JAMAIS imprimés : un papier déjà en cuisine ne se
 * rattrape pas, c'est le bon d'annulation qui s'en charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_cuisine', function (Blueprint $table): void {
            $table->timestamp('sans_objet_le')->nullable()->after('nombre_impressions');
        });

        Schema::table('lignes_bon_cuisine', function (Blueprint $table): void {
            $table->boolean('sans_objet')->default(false)->after('commentaire');
        });
    }

    public function down(): void
    {
        Schema::table('lignes_bon_cuisine', function (Blueprint $table): void {
            $table->dropColumn('sans_objet');
        });

        Schema::table('bons_cuisine', function (Blueprint $table): void {
            $table->dropColumn('sans_objet_le');
        });
    }
};
