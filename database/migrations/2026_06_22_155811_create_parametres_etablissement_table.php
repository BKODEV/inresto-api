<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('parametres_etablissement', function (Blueprint $table) {
            $table->id();
            // Une seule fiche de paramètres par site (clé unique).
            $table->foreignId('site_id')->unique()->constrained()->restrictOnDelete();
            $table->string('nom_commercial');
            $table->string('slogan')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('adresse')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('site_web')->nullable();
            // Informations légales (facultatives, affichées si renseignées).
            $table->string('raison_sociale')->nullable();
            $table->string('ncc')->nullable();
            $table->string('rccm')->nullable();
            $table->string('regime_fiscal')->nullable();
            $table->string('devise', 10)->default('XOF');
            $table->text('mentions_pied_page')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parametres_etablissement');
    }
};
