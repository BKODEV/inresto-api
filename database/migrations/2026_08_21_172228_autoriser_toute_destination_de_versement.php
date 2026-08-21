<?php

declare(strict_types=1);

use App\Enums\DestinationVersement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libère `versements.destination` de sa contrainte SQL.
 *
 * La colonne était un `enum` figé sur quatre valeurs. Ajouter « personnel »
 * pour les reversements de pourboires a donc été refusé par PostgreSQL — mais
 * accepté par SQLite, qui avait silencieusement perdu la contrainte en
 * reconstruisant la table lors de l'ajout de `beneficiaire_id`. La suite de
 * tests était verte pendant que la vraie base rejetait l'écriture.
 *
 * Même remède que pour `impressions.type` : le cast
 * {@see DestinationVersement} du modèle fait désormais seul autorité.
 * La contrainte vivait à deux endroits, dont un que les tests ne voyaient pas ;
 * elle n'en a plus qu'un.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('versements', function (Blueprint $table): void {
            $table->string('destination', 30)->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE versements DROP CONSTRAINT IF EXISTS versements_destination_check');
        }
    }

    public function down(): void
    {
        DB::table('versements')->where('destination', 'personnel')->delete();

        // `enum()->change()` produit du SQL invalide sur PostgreSQL : le CHECK
        // y est collé au ALTER COLUMN TYPE, que le moteur refuse.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE versements ALTER COLUMN destination TYPE varchar(255)');
            DB::statement("ALTER TABLE versements ADD CONSTRAINT versements_destination_check CHECK (destination IN ('coffre', 'banque', 'direction', 'autre'))");

            return;
        }

        Schema::table('versements', function (Blueprint $table): void {
            $table->enum('destination', ['coffre', 'banque', 'direction', 'autre'])->change();
        });
    }
};
