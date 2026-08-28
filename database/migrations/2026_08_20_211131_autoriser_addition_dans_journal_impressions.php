<?php

declare(strict_types=1);

use App\Enums\TypeImpression;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ouvre le journal d'impressions à l'addition, et à ce qui viendra après.
 *
 * La colonne était un `enum('facture', 'recu')` : chaque nouveau document
 * d'argent aurait exigé sa migration, sur une table en ajout seul qu'on ne
 * souhaite pas retoucher. Le cast {@see TypeImpression} du modèle
 * fait désormais seul autorité — la contrainte vivait à deux endroits, elle
 * n'en a plus qu'un.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impressions', function (Blueprint $table): void {
            $table->string('type', 30)->change();
        });

        // PostgreSQL pose la contrainte CHECK à part du type : `change()` élargit
        // la colonne mais laisse la contrainte, qui refuserait encore 'addition'.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE impressions DROP CONSTRAINT IF EXISTS impressions_type_check');
        }
    }

    public function down(): void
    {
        // Les additions déjà journalisées n'ont pas de représentation dans
        // l'ancien schéma : le retour arrière les perd, faute de mieux.
        DB::table('impressions')->where('type', 'addition')->delete();

        // `enum()->change()` produit du SQL invalide sur PostgreSQL (le CHECK
        // est collé au ALTER COLUMN TYPE, que le moteur refuse) : on repose le
        // type et sa contrainte en deux ordres. SQLite reconstruit sa table et
        // accepte le passage par le schéma.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE impressions ALTER COLUMN type TYPE varchar(255)');
            DB::statement("ALTER TABLE impressions ADD CONSTRAINT impressions_type_check CHECK (type IN ('facture', 'recu'))");

            return;
        }

        Schema::table('impressions', function (Blueprint $table): void {
            $table->enum('type', ['facture', 'recu'])->change();
        });
    }
};
