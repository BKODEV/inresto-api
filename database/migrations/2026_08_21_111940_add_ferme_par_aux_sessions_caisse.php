<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a fermé la caisse, et pas seulement qui l'a ouverte.
 *
 * `user_id` désigne le responsable de l'ouverture ; une session peut traverser
 * une relève et être close par quelqu'un d'autre. Or c'est le geste de
 * fermeture qui fige l'écart — le signer était la moitié manquante de la piste
 * d'audit, sur le modèle d'`encaisse_par` sur les commandes.
 *
 * Nullable : les sessions fermées avant cette colonne n'ont pas de signataire,
 * et on ne l'invente pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->foreignUuid('ferme_par')->nullable()->after('ferme_le')->constrained('users')->nullOnDelete();
        });

        $this->reposerIndexUneSessionOuverte();
    }

    public function down(): void
    {
        Schema::table('sessions_caisse', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ferme_par');
        });

        $this->reposerIndexUneSessionOuverte();
    }

    /**
     * Repose l'index unique PARTIEL « une seule session ouverte par caisse ».
     *
     * Ajouter une clé étrangère fait reconstruire la table sous SQLite, et la
     * reconstruction recrée les index depuis l'introspection de Laravel — qui
     * ne connaît pas la clause `WHERE`. L'index en ressortait unique tout court :
     * une caisse n'aurait plus jamais pu rouvrir après sa première session.
     * PostgreSQL, lui, se contente d'un ADD COLUMN et n'est pas concerné ; on
     * repose l'index pour les deux, l'opération étant idempotente.
     */
    private function reposerIndexUneSessionOuverte(): void
    {
        DB::statement('DROP INDEX IF EXISTS sessions_caisse_une_ouverte_par_caisse');
        DB::statement(
            "CREATE UNIQUE INDEX sessions_caisse_une_ouverte_par_caisse
             ON sessions_caisse (caisse_id) WHERE statut = 'ouverte'"
        );
    }
};
