<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index des chemins que la trésorerie parcourt à chaque appel.
 *
 * Tous les agrégats d'une session remontent par `commandes_client` puis
 * `paiements` — position du tiroir, pourboires dus, théorique par contenant —
 * et ces deux chemins ne portaient que leur clé étrangère. Tant qu'on ne les
 * empruntait qu'à la fermeture, une fois par service, cela ne se voyait pas.
 * Une position consolidée les emprunte à chaque ouverture d'écran.
 *
 * Migration d'INDEX SEULS, sans aucune clé étrangère : c'est ce qui évite la
 * reconstruction de table par SQLite, laquelle perd au passage les index
 * partiels et les contraintes CHECK (l'index « une seule session ouverte par
 * caisse » en particulier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_client', function (Blueprint $table): void {
            $table->index(['session_caisse_id', 'statut'], 'commandes_client_session_statut_index');
        });

        Schema::table('paiements', function (Blueprint $table): void {
            $table->index('commande_client_id', 'paiements_commande_index');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_client', function (Blueprint $table): void {
            $table->dropIndex('commandes_client_session_statut_index');
        });

        Schema::table('paiements', function (Blueprint $table): void {
            $table->dropIndex('paiements_commande_index');
        });
    }
};
