<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi d'impression des bons de cuisine.
 *
 * `imprime_le` vide = le bon attend à la caisse : c'est la file « à imprimer »,
 * qui garantit qu'aucun envoi n'est oublié. `nombre_impressions` distingue
 * l'original du DUPLICATA : un bon ressorti ne doit pas être pris en cuisine
 * pour une nouvelle commande.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bons_cuisine', function (Blueprint $table): void {
            $table->timestamp('imprime_le')->nullable()->after('emis_le');
            $table->unsignedInteger('nombre_impressions')->default(0)->after('imprime_le');

            $table->index(['point_de_vente_id', 'imprime_le']);
        });
    }

    public function down(): void
    {
        Schema::table('bons_cuisine', function (Blueprint $table): void {
            $table->dropIndex(['point_de_vente_id', 'imprime_le']);
            $table->dropColumn(['imprime_le', 'nombre_impressions']);
        });
    }
};
