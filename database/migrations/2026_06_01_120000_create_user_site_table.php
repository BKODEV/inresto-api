<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Périmètre d'un utilisateur = le SITE (et non le point de vente). Le PDV
     * appartenant à exactement un site, le site d'un PDV reste déductible.
     */
    public function up(): void
    {
        Schema::create('user_site', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('site_id')
                ->constrained('sites')
                ->cascadeOnDelete();
            $table->boolean('est_principal')->default(false); // site de rattachement principal

            $table->unique(['user_id', 'site_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_site');
    }
};
